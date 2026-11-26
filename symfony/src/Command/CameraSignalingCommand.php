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
 * Media flows peer-to-peer (WebRTC) between the tablet and the viewer devices:
 * the only purpose of this server is to relay session descriptions and ICE
 * candidates between the single camera publisher and its viewers, so a
 * dependency-free implementation is enough here.
 *
 * Protocol (JSON text frames):
 *   - server → publisher: {registered} {viewer-joined,viewerId} {viewer-left,viewerId}
 *   - server → viewer:    {waiting} {offer,sdp} {ice,candidate} {publisher-left}
 *   - publisher → server: {offer,to,sdp} {ice,to,candidate}
 *   - viewer → server:    {answer,sdp} {ice,candidate}
 */
#[AsCommand(
    name: 'app:camera:ws',
    description: 'Runs the camera signaling WebSocket server (one tablet publisher, many viewers).',
)]
class CameraSignalingCommand extends Command
{
    private const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_TOTAL_BUFFER_BYTES = 131072;
    private const MAX_SIGNALING_MESSAGE_BYTES = 32768;
    private const STALE_SECONDS = 120;
    private const PING_INTERVAL_SECONDS = 30;
    private const CLOSE_INVALID_TOKEN = 4001;
    private const CLOSE_PUBLISHER_BUSY = 4002;

    /**
     * @var array<int, array{socket: resource, id: string, role: string, buffer: string, fragment: string, lastSeen: int}>
     */
    private array $clients = [];
    private ?int $publisherId = null;
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

        $this->clients[$key]['role'] = $role;
        if ($role === CameraTokenService::ROLE_VIEWER) {
            $this->clients[$key]['id'] = sprintf('viewer-%d', $key);
        }

        if ($role === CameraTokenService::ROLE_PUBLISHER) {
            if ($this->publisherId !== null) {
                ($this->log)('Rejected publisher: another publisher is already streaming.');
                $this->sendJson($this->clients[$key]['socket'], [
                    'type' => 'error',
                    'code' => 'publisher_busy',
                    'message' => 'Une autre tablette diffuse déjà sa caméra.',
                ]);
                $this->closeConnection($key, false, self::CLOSE_PUBLISHER_BUSY);

                return;
            }
            $this->publisherId = $key;
            $this->clients[$key]['id'] = 'publisher';
            ($this->log)('Publisher registered.');
        }

        $this->sendJson($this->clients[$key]['socket'], [
            'type' => $role === CameraTokenService::ROLE_PUBLISHER ? 'registered' : 'waiting',
        ]);

        if ($role === CameraTokenService::ROLE_VIEWER) {
            $this->notifyPublisher('viewer-joined', ['viewerId' => $this->clients[$key]['id']]);
        }

        $this->consumeFrames($key);
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
            $this->relayFromPublisher($data, $type);

            return;
        }

        if ($client['role'] === CameraTokenService::ROLE_VIEWER) {
            $this->relayFromViewer($client, $data, $type);
        }
    }

    private function relayFromPublisher(array $data, string $type): void
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
            if ($client['role'] === CameraTokenService::ROLE_VIEWER && $client['id'] === $viewerId) {
                $this->sendJson($client['socket'], $payload);

                return;
            }
        }
    }

    private function relayFromViewer(array $viewer, array $data, string $type): void
    {
        $payload = ['viewerId' => $viewer['id']];
        match ($type) {
            'answer' => $payload['sdp'] = $data['sdp'],
            'ice' => $payload['candidate'] = $data['candidate'],
            default => null,
        };
        $this->notifyPublisher($type === 'ice' ? 'ice' : 'answer', $payload);
    }

    private function notifyPublisher(string $type, array $payload): void
    {
        if ($this->publisherId === null || !isset($this->clients[$this->publisherId])) {
            return;
        }
        $this->sendJson($this->clients[$this->publisherId]['socket'], ['type' => $type] + $payload);
    }

    private function broadcastToViewers(string $type): void
    {
        foreach ($this->clients as $client) {
            if ($client['role'] === CameraTokenService::ROLE_VIEWER) {
                $this->sendJson($client['socket'], ['type' => $type]);
            }
        }
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

    private function closeConnection(int $key, bool $withCloseFrame = true, int $closeCode = 1000): void
    {
        if (!isset($this->clients[$key])) {
            return;
        }

        $client = $this->clients[$key];
        if ($client['role'] === CameraTokenService::ROLE_PUBLISHER) {
            if ($this->publisherId === $key) {
                $this->publisherId = null;
            }
            if ($withCloseFrame) {
                ($this->log)('Publisher left; notifying viewers.');
            }
            $this->broadcastToViewers('publisher-left');
        }

        if ($client['role'] === CameraTokenService::ROLE_VIEWER) {
            $this->notifyPublisher('viewer-left', ['viewerId' => $client['id']]);
        }

        if ($withCloseFrame && $client['role'] !== '') {
            $this->writeFrame($client['socket'], 0x8, pack('n', $closeCode), true);
        }
        @fclose($client['socket']);
        unset($this->clients[$key]);
    }
}
