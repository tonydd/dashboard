<?php

namespace App\Command;

use App\Service\CameraTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Minimal RFC 6455 WebSocket server dedicated to camera signaling.
 *
 * Media flows peer-to-peer (WebRTC) between the camera publishers (tablets)
 * and the viewer devices; the only purpose of this server is to relay session
 * descriptions and ICE candidates, so a dependency-free implementation is
 * enough here. Several publishers may register at the same time; each one
 * picks a display name and reports whether its camera is currently ON, which
 * is what viewers poll (healthcheck) to keep their camera list fresh.
 *
 * Protocol (JSON text frames):
 *   - publisher → server: {status,cameraOn} {offer,to,sdp} {ice,to,candidate}
 *   - viewer → server:    {watch,to} {cameras} {answer,sdp} {ice,candidate}
 *   - server → publisher: {registered,id,viewers} {viewer-joined,viewerId} {viewer-left,viewerId} {evicted}
 *   - server → viewer:    {cameras,list:[{id,name,cameraOn}]} {watching,to} {offer,sdp} {ice,candidate} {publisher-left} {publisher-stopped}
 */
#[AsCommand(
    name: 'app:camera:ws',
    description: 'Runs the camera signaling WebSocket server (many named camera publishers, many viewers).',
)]
class CameraSignalingCommand extends Command
{
    private const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_TOTAL_BUFFER_BYTES = 131072;
    private const MAX_SIGNALING_MESSAGE_BYTES = 32768;
    private const MAX_CLIENT_NAME_LENGTH = 60;
    private const STALE_SECONDS = 120;
    private const PING_INTERVAL_SECONDS = 30;
    private const CLOSE_INVALID_TOKEN = 4001;
    private const CLOSE_EVICTED = 4002;

    /**
     * Publishers carry a display name and the current camera state; viewers
     * remember which publisher they watch so relaying stays unambiguous.
     *
     * @var array<int, array{socket: resource, id: string, role: string, buffer: string, fragment: string, lastSeen: int, name: string, cameraOn: bool, watched: int|null}>
     */
    private array $clients = [];
    private int $nextCameraId = 0;
    private int $nextViewerId = 0;
    /** @var resource|null */
    private $server;
    private int $lastPingAt = 0;
    private \Closure $log;

    public function __construct(
        private readonly CameraTokenService $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Listen host', '0.0.0.0')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Listen port', '8080');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');

        $this->log = static function (string $message) use ($output): void {
            $output->writeln(sprintf('[%s] %s', date(DATE_ATOM), $message));
        };

        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if ($server === false) {
            $output->writeln(sprintf('<error>Unable to listen on %s:%d: %s</error>', $host, $port, $errstr));

            return Command::FAILURE;
        }
        $this->server = $server;
        ($this->log)(sprintf('Camera signaling server listening on %s:%d', $host, $port));

        while (true) {
            $read = array_column($this->clients, 'socket');
            if ($this->server) {
                $read[] = $this->server;
            }
            $write = null;
            $except = null;

            $selected = @stream_select($read, $write, $except, 1);
            if ($selected > 0) {
                foreach ($read as $socket) {
                    if ($socket === $this->server) {
                        $this->acceptConnection();
                    } else {
                        $this->readFromConnection($socket);
                    }
                }
            }

            $this->keepAlive();
        }

        return Command::SUCCESS;
    }

    private function keepAlive(): void
    {
        foreach ($this->clients as $key => $client) {
            if ($client['role'] === '' || $client['lastSeen'] + self::STALE_SECONDS >= time()) {
                continue;
            }
            ($this->log)(sprintf('Closing stale connection %s.', $client['id'] ?: (string) $key));
            $this->closeConnection($key, false);
        }

        // Application-level pings keep home routers from dropping idle signaling sockets.
        if (count($this->clients) > 0 && time() - $this->lastPingAt >= self::PING_INTERVAL_SECONDS) {
            $this->lastPingAt = time();
            foreach ($this->clients as $client) {
                if ($client['role'] !== '') {
                    $this->writeFrame($client['socket'], 0x9, '', true);
                }
            }
        }
    }

    private function acceptConnection(): void
    {
        $client = @stream_socket_accept($this->server, 0, $peerName);
        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);
        $this->clients[(int) $client] = [
            'socket' => $client,
            'id' => '',
            'role' => '',
            'buffer' => '',
            'fragment' => '',
            'lastSeen' => time(),
            'name' => '',
            'cameraOn' => false,
            'watched' => null,
        ];
    }

    /**
     * @param resource $socket
     */
    private function readFromConnection($socket): void
    {
        $key = (int) $socket;
        if (!isset($this->clients[$key])) {
            return;
        }

        $chunk = @fread($socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($socket))) {
            $this->closeConnection($key);

            return;
        }
        $this->clients[$key]['buffer'] .= $chunk;
        $this->clients[$key]['lastSeen'] = time();

        if ($this->clients[$key]['role'] === '') {
            $this->handleHandshake($key);

            return;
        }

        $this->consumeFrames($key);
    }

    private function handleHandshake(int $key): void
    {
        $request = $this->clients[$key]['buffer'];
        if (!str_contains($request, "\r\n\r\n")) {
            if (strlen($request) > 16384) {
                $this->closeConnection($key);
            }

            return;
        }

        [$headerBlock, $remaining] = explode("\r\n\r\n", $request, 2);
        $this->clients[$key]['buffer'] = $remaining;

        $headers = [];
        foreach (explode("\r\n", substr($headerBlock, (int) strpos($headerBlock, "\r\n") + 2)) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        $websocketKey = $headers['sec-websocket-key'] ?? '';
        if (strtolower($headers['upgrade'] ?? '') !== 'websocket' || $websocketKey === '' || strtolower($headers['connection'] ?? '') !== 'upgrade') {
            $this->rejectHandshake($key, '400 Bad Request');

            return;
        }

        preg_match('/^GET\s+(\S+)\s+HTTP/', $request, $requestLine);
        parse_str(parse_url($requestLine[1] ?? '', PHP_URL_QUERY) ?? '', $queryValues);
        $role = $this->tokens->resolve((string) ($queryValues['token'] ?? ''));
        if ($role === null) {
            ($this->log)('Rejected camera websocket connection: invalid or expired token.');
            $this->rejectHandshake($key, '403 Forbidden');

            return;
        }

        $accept = base64_encode(sha1($websocketKey.self::WS_GUID, true));
        fwrite($this->clients[$key]['socket'], "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");

        if ($role === CameraTokenService::ROLE_PUBLISHER) {
            $this->registerPublisher($key, (string) ($queryValues['name'] ?? ''));
        } else {
            $this->clients[$key]['role'] = CameraTokenService::ROLE_VIEWER;
            $this->clients[$key]['id'] = sprintf('viewer-%d', ++$this->nextViewerId);
            // Initial camera list; viewers then poll it every few seconds (healthcheck).
            $this->sendJson($this->clients[$key]['socket'], $this->camerasMessage());
        }

        $this->consumeFrames($key);
    }

    private function registerPublisher(int $key, string $rawName): void
    {
        $name = trim(preg_replace('/[[:cntrl:]]/u', '', $rawName) ?? '');
        if (mb_strlen($name) > self::MAX_CLIENT_NAME_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_CLIENT_NAME_LENGTH);
        }

        // A page reload reconnects under the same name: evict the publisher
        // session it replaces instead of letting a half-open socket linger on.
        foreach ($this->clients as $otherKey => $other) {
            if ($otherKey !== $key && $other['role'] === CameraTokenService::ROLE_PUBLISHER && $other['name'] === $name) {
                ($this->log)(sprintf('Evicting previous publisher "%s".', $name));
                $this->sendJson($other['socket'], ['type' => 'evicted']);
                $this->closeConnection($otherKey, true, self::CLOSE_EVICTED, false);

                // Viewers watching the evicted session follow the new one.
                foreach ($this->clients as $viewerKey => $viewer) {
                    if ($viewer['role'] === CameraTokenService::ROLE_VIEWER && $viewer['watched'] === $otherKey) {
                        $this->clients[$viewerKey]['watched'] = $key;
                        $this->sendJson($viewer['socket'], ['type' => 'watching', 'to' => $this->clients[$key]['id']]);
                    }
                }
            }
        }

        $this->clients[$key]['role'] = CameraTokenService::ROLE_PUBLISHER;
        $this->clients[$key]['id'] = sprintf('camera-%d', ++$this->nextCameraId);
        $this->clients[$key]['name'] = $name !== '' ? $name : $this->clients[$key]['id'];
        ($this->log)(sprintf('Publisher "%s" registered as %s.', $this->clients[$key]['name'], $this->clients[$key]['id']));
        $this->sendJson($this->clients[$key]['socket'], [
            'type' => 'registered',
            'id' => $this->clients[$key]['id'],
            'viewers' => $this->watcherIds($key),
        ]);
        $this->broadcastCameras();
    }

    /** @return string[] */
    private function watcherIds(int $publisherKey): array
    {
        $ids = [];
        foreach ($this->clients as $client) {
            if ($client['role'] === CameraTokenService::ROLE_VIEWER && $client['watched'] === $publisherKey) {
                $ids[] = $client['id'];
            }
        }

        return $ids;
    }

    /**
     * The healthcheck payload viewers rely on: only connections still open
     * with their camera reportedly ON are flagged as usable.
     *
     * @return array<int, array{id: string, name: string, cameraOn: bool}>
     */
    private function cameraList(): array
    {
        $list = [];
        foreach ($this->clients as $client) {
            if ($client['role'] === CameraTokenService::ROLE_PUBLISHER) {
                $list[] = [
                    'id' => $client['id'],
                    'name' => $client['name'],
                    'cameraOn' => $client['cameraOn'],
                ];
            }
        }

        return $list;
    }

    private function camerasMessage(): array
    {
        return ['type' => 'cameras', 'list' => $this->cameraList()];
    }

    private function broadcastCameras(): void
    {
        $message = $this->camerasMessage();
        foreach ($this->clients as $client) {
            if ($client['role'] === CameraTokenService::ROLE_VIEWER) {
                $this->sendJson($client['socket'], $message);
            }
        }
    }

    private function rejectHandshake(int $key, string $status): void
    {
        if (isset($this->clients[$key])) {
            @fwrite($this->clients[$key]['socket'], "HTTP/1.1 {$status}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        }
        $this->closeConnection($key, false);
    }

    private function consumeFrames(int $key): void
    {
        while (isset($this->clients[$key])) {
            $frame = $this->decodeFrame($this->clients[$key]['buffer']);
            if ($frame === null) {
                break;
            }

            [$consumedBytes, $opcode, $payload, $fin, $error] = $frame;
            $this->clients[$key]['buffer'] = substr($this->clients[$key]['buffer'], $consumedBytes);

            if ($error || (array_key_exists('role', $this->clients[$key]) && $this->assembleMessage($key, $opcode, $payload, $fin) === false)) {
                $this->closeConnection($key);

                return;
            }
        }

        if (isset($this->clients[$key])) {
            $buffered = strlen($this->clients[$key]['buffer']) + strlen($this->clients[$key]['fragment']);
            if ($buffered > self::MAX_TOTAL_BUFFER_BYTES) {
                $this->closeConnection($key);
            }
        }
    }

    /**
     * Assembles fragmented text messages, then dispatches complete ones.
     *
     * @return bool|null false on protocol violation, null when the message is not complete yet
     */
    private function assembleMessage(int $key, int $opcode, string $payload, bool $fin): ?bool
    {
        $client = &$this->clients[$key];

        switch ($opcode) {
            case 0x1: // text
                if ($client['fragment'] !== '') {
                    return false;
                }
                if ($fin) {
                    $this->handleMessage($key, $payload);

                    return null;
                }
                $client['fragment'] = $payload;

                return null;
            case 0x0: // continuation
                if ($client['fragment'] === '') {
                    return false;
                }
                $client['fragment'] .= $payload;
                if (strlen($client['fragment']) > self::MAX_SIGNALING_MESSAGE_BYTES) {
                    return false;
                }
                if ($fin) {
                    $message = $client['fragment'];
                    $client['fragment'] = '';
                    $this->handleMessage($key, $message);
                }

                return null;
            case 0x8: // close
                $this->writeFrame($client['socket'], 0x8, $payload !== '' ? substr($payload, 0, 2) : '', true);
                $this->closeConnection($key);

                return null;
            case 0x9: // ping
                $this->writeFrame($client['socket'], 0xA, $payload, true);

                return null;
            case 0xA: // pong
            default:
                return null;
        }
    }

    private function decodeFrame(string $buffer): ?array
    {
        $bufferLength = strlen($buffer);
        if ($bufferLength < 2) {
            return null;
        }

        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);
        $fin = ($firstByte & 0x80) !== 0;
        $opcode = $firstByte & 0x0F;
        $length = $secondByte & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if ($bufferLength < 4) {
                return null;
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if ($bufferLength < 10) {
                return null;
            }
            $length = (int) unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if (!($secondByte & 0x80)) {
            // Clients must mask their frames (RFC 6455 §5.1).
            return [0, 0, '', false, true];
        }

        if ($bufferLength < $offset + 4) {
            return null;
        }
        if ($bufferLength < $offset + 4 + $length) {
            return null;
        }

        $mask = substr($buffer, $offset, 4);
        $payload = substr($buffer, $offset + 4, $length);
        for ($i = 0; $i < $length; $i++) {
            $payload[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }

        return [$offset + 4 + $length, $opcode, $payload, $fin, false];
    }

    private function handleMessage(int $key, string $message): void
    {
        $client = $this->clients[$key] ?? null;
        if ($client === null) {
            return;
        }

        $data = json_decode($message, true);
        if (!is_array($data)) {
            return;
        }

        $type = (string) ($data['type'] ?? '');
        if ($client['role'] === CameraTokenService::ROLE_PUBLISHER) {
            if ($type === 'stop') {
                $this->updatePublisherStatus($key, false);
            } elseif ($type === 'status' && is_bool($data['cameraOn'] ?? null)) {
                $this->updatePublisherStatus($key, $data['cameraOn']);
            } else {
                $this->relayFromPublisher($key, $data, $type);
            }

            return;
        }

        if ($client['role'] === CameraTokenService::ROLE_VIEWER) {
            $this->handleViewerMessage($key, $client, $data, $type);
        }
    }

    private function updatePublisherStatus(int $key, bool $cameraOn): void
    {
        if (($this->clients[$key]['cameraOn'] ?? null) === $cameraOn) {
            return;
        }
        $this->clients[$key]['cameraOn'] = $cameraOn;
        ($this->log)(sprintf('Publisher "%s" camera is %s.', $this->clients[$key]['name'], $cameraOn ? 'ON' : 'OFF'));

        if ($cameraOn) {
            // Viewers bound to this publisher get handed back to it so it can
            // renegotiate as soon as the camera comes back.
            foreach ($this->clients as $client) {
                if ($client['role'] === CameraTokenService::ROLE_VIEWER && $client['watched'] === $key) {
                    $this->sendJson($this->clients[$key]['socket'], ['type' => 'viewer-joined', 'viewerId' => $client['id']]);
                }
            }
        } else {
            foreach ($this->clients as $client) {
                if ($client['role'] === CameraTokenService::ROLE_VIEWER && $client['watched'] === $key) {
                    $this->sendJson($client['socket'], ['type' => 'publisher-stopped']);
                }
            }
        }
        $this->broadcastCameras();
    }

    private function handleViewerMessage(int $key, array $client, array $data, string $type): void
    {
        if ($type === 'cameras') {
            $this->sendJson($client['socket'], $this->camerasMessage());

            return;
        }

        if ($type === 'watch') {
            $targetId = (string) ($data['to'] ?? '');
            foreach ($this->clients as $publisherKey => $publisher) {
                if ($publisher['role'] !== CameraTokenService::ROLE_PUBLISHER || $publisher['id'] !== $targetId) {
                    continue;
                }
                if ($client['watched'] === $publisherKey) {
                    return; // already watching this camera, nothing to do
                }
                if ($client['watched'] !== null && isset($this->clients[$client['watched']])) {
                    $previous = $this->clients[$client['watched']];
                    $this->sendJson($previous['socket'], ['type' => 'viewer-left', 'viewerId' => $client['id']]);
                }
                $this->clients[$key]['watched'] = $publisherKey;
                $this->sendJson($publisher['socket'], ['type' => 'viewer-joined', 'viewerId' => $client['id']]);
                // Acknowledge the binding so viewers stop re-watching.
                $this->sendJson($client['socket'], ['type' => 'watching', 'to' => $publisher['id']]);

                return;
            }

            return;
        }

        $this->relayFromViewer($client, $data, $type);
    }

    private function relayFromPublisher(int $publisherKey, array $data, string $type): void
    {
        $viewerId = (string) ($data['to'] ?? '');
        if ($viewerId === '') {
            return;
        }

        $relayType = $type === 'offer' ? 'offer' : ($type === 'ice' ? 'ice' : null);
        if ($relayType === null) {
            return;
        }

        $payload = ['type' => $relayType];
        if ($type === 'offer' && isset($data['sdp'])) {
            $payload['sdp'] = $data['sdp'];
        }
        if ($type === 'ice' && isset($data['candidate'])) {
            $payload['candidate'] = $data['candidate'];
        }

        foreach ($this->clients as $client) {
            if ($client['role'] === CameraTokenService::ROLE_VIEWER
                && $client['id'] === $viewerId
                && $client['watched'] === $publisherKey
            ) {
                $this->sendJson($client['socket'], $payload);

                return;
            }
        }
    }

    private function relayFromViewer(array $viewer, array $data, string $type): void
    {
        $publisherKey = $viewer['watched'];
        if ($publisherKey === null || !isset($this->clients[$publisherKey])) {
            return;
        }

        $payload = ['viewerId' => $viewer['id']];
        match ($type) {
            'answer' => $payload['sdp'] = $data['sdp'],
            'ice' => $payload['candidate'] = $data['candidate'],
            default => null,
        };
        $this->sendJson($this->clients[$publisherKey]['socket'], ['type' => $type === 'ice' ? 'ice' : 'answer'] + $payload);
    }

    private function sendJson($socket, array $payload): void
    {
        /** @var resource $socket */
        $this->writeFrame($socket, 0x1, json_encode($payload, JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * @param resource $socket
     */
    private function writeFrame($socket, int $opcode, string $payload, bool $fin): void
    {
        $length = strlen($payload);
        if ($length > self::MAX_SIGNALING_MESSAGE_BYTES) {
            return;
        }

        $header = chr(($fin ? 0x80 : 0x00) | $opcode);
        if ($length < 126) {
            $header .= chr($length);
        } else {
            $header .= chr(126).pack('n', $length);
        }

        @fwrite($socket, $header.$payload);
    }

    private function closeConnection(int $key, bool $withCloseFrame = true, int $closeCode = 1000, bool $announce = true): void
    {
        if (!isset($this->clients[$key])) {
            return;
        }

        $client = $this->clients[$key];
        if ($client['role'] === CameraTokenService::ROLE_PUBLISHER) {
            if ($withCloseFrame && $announce) {
                ($this->log)(sprintf('Publisher "%s" left; notifying viewers.', $client['name']));
            }
            if ($announce) {
                foreach ($this->clients as $other) {
                    if ($other['role'] === CameraTokenService::ROLE_VIEWER && $other['watched'] === $key) {
                        $this->sendJson($other['socket'], ['type' => 'publisher-left']);
                    }
                }
                $this->broadcastCameras();
            }
        }

        if ($client['role'] === CameraTokenService::ROLE_VIEWER) {
            $publisherKey = $client['watched'];
            if ($publisherKey !== null && isset($this->clients[$publisherKey])) {
                $this->sendJson($this->clients[$publisherKey]['socket'], ['type' => 'viewer-left', 'viewerId' => $client['id']]);
            }
        }

        if ($withCloseFrame && $client['role'] !== '') {
            $this->writeFrame($client['socket'], 0x8, pack('n', $closeCode), true);
        }
        @fclose($client['socket']);
        unset($this->clients[$key]);
    }
}
