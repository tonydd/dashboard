<?php

namespace App\Controller;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use App\Service\DashboardFeedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StatusController extends AbstractController
{
    private const FEEDS = [
        'solar' => [KeyValueKey::Solar, 300],
        'fuel' => [KeyValueKey::Fuel, 10800],
        'thermor' => [KeyValueKey::Thermor, 300],
        'waterQuality' => [KeyValueKey::WaterQuality, 93600],
    ];

    // The scheduler emits messages every minute; beyond this the consumer is dead.
    private const HEARTBEAT_STALE_SECONDS = 300;
    private const CAMERA_WS_TIMEOUT_SECONDS = 1.0;

    public function __construct(
        private readonly KeyValueRepository $keyValues,
        private readonly DashboardFeedService $feeds,
        private readonly string $atlanticTokenUrl,
        private readonly string $atlanticTokenAuthorization,
        private readonly string $atlanticUsername,
        private readonly string $atlanticPassword,
        private readonly string $cameraWsInternalHost,
        private readonly int $cameraWsInternalPort,
    ) {
    }

    #[Route('/status', name: 'dashboard_status', methods: ['GET'])]
    public function status(): Response
    {
        if (!$this->getUser()) {
            return $this->render('dashboard/login.html.twig');
        }

        return $this->render('dashboard/status.html.twig');
    }

    #[Route('/api/status', name: 'api_status', methods: ['GET'])]
    public function apiStatus(): JsonResponse
    {
        $now = new \DateTimeImmutable();

        return $this->json([
            'now' => $now->format(DATE_ATOM),
            'server' => $this->serverStatus(),
            'messenger' => $this->messengerStatus($now),
            'cameraWs' => $this->cameraWsStatus(),
            'config' => [
                'weather' => $this->feeds->isWeatherConfigured(),
                'thermor' => $this->isThermorConfigured(),
            ],
            'feeds' => $this->feedsStatus($now),
        ]);
    }

    /**
     * Host metrics read from /proc; inside a container these reflect the host,
     * which is what matters on a single-board home server.
     *
     * @return array<string, mixed>
     */
    private function serverStatus(): array
    {
        return [
            'hostname' => gethostname() ?: null,
            'php' => PHP_VERSION,
            'uptimeSeconds' => $this->uptimeSeconds(),
            'loadAvg' => sys_getloadavg(),
            'cores' => $this->coreCount(),
            'memory' => $this->memoryStatus(),
            'swap' => $this->swapStatus(),
            'disk' => $this->diskStatus(),
        ];
    }

    private function uptimeSeconds(): ?int
    {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime === false) {
            return null;
        }
        $seconds = (float) strtok($uptime, ' ');

        return $seconds > 0 ? (int) $seconds : null;
    }

    private function coreCount(): ?int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo === false) {
            return null;
        }

        return substr_count($cpuinfo, 'processor') ?: null;
    }

    /**
     * @return array{totalBytes: int, availableBytes: int, usedPercent: int}|null
     */
    private function memoryStatus(): ?array
    {
        $info = $this->parseMemInfo();
        if ($info === null || !isset($info['MemTotal'], $info['MemAvailable'])) {
            return null;
        }

        return [
            'totalBytes' => $info['MemTotal'],
            'availableBytes' => $info['MemAvailable'],
            'usedPercent' => (int) round(100 * (1 - $info['MemAvailable'] / $info['MemTotal'])),
        ];
    }

    /**
     * @return array{totalBytes: int, freeBytes: int, usedPercent: int}|null
     */
    private function swapStatus(): ?array
    {
        $info = $this->parseMemInfo();
        if ($info === null || !isset($info['SwapTotal']) || $info['SwapTotal'] === 0) {
            return null;
        }

        return [
            'totalBytes' => $info['SwapTotal'],
            'freeBytes' => $info['SwapFree'] ?? 0,
            'usedPercent' => (int) round(100 * (1 - ($info['SwapFree'] ?? 0) / $info['SwapTotal'])),
        ];
    }

    /**
     * @return array<string, int>|null key/values in bytes
     */
    private function parseMemInfo(): ?array
    {
        $content = @file_get_contents('/proc/meminfo');
        if ($content === false) {
            return null;
        }

        $values = [];
        if (preg_match_all('/^(\w+):\s+(\d+) kB$/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $values[$match[1]] = (int) $match[2] * 1024;
            }
        }

        return $values;
    }

    /**
     * @return array{totalBytes: int, freeBytes: int, usedPercent: int}
     */
    private function diskStatus(): array
    {
        $totalBytes = (int) disk_total_space('/var/www/html');
        $freeBytes = (int) disk_free_space('/var/www/html');

        return [
            'totalBytes' => $totalBytes,
            'freeBytes' => $freeBytes,
            'usedPercent' => $totalBytes > 0 ? (int) round(100 * (1 - $freeBytes / $totalBytes)) : 0,
        ];
    }

    /**
     * @return array{lastHeartbeat: ?string, ageSeconds: ?int, alive: bool}
     */
    private function messengerStatus(\DateTimeImmutable $now): array
    {
        $raw = $this->keyValues->getValue(KeyValueKey::MessengerHeartbeat);
        $heartbeat = is_string($raw)
            ? \DateTimeImmutable::createFromFormat(DATE_ATOM, $raw)
            : false;
        $ageSeconds = $heartbeat instanceof \DateTimeImmutable
            ? max(0, $now->getTimestamp() - $heartbeat->getTimestamp())
            : null;

        return [
            'lastHeartbeat' => $heartbeat instanceof \DateTimeImmutable ? $heartbeat->format(DATE_ATOM) : null,
            'ageSeconds' => $ageSeconds,
            'alive' => $ageSeconds !== null && $ageSeconds <= self::HEARTBEAT_STALE_SECONDS,
        ];
    }

    /**
     * @return array{target: string, reachable: bool}
     */
    private function cameraWsStatus(): array
    {
        $target = sprintf('%s:%d', $this->cameraWsInternalHost, $this->cameraWsInternalPort);
        $socket = @fsockopen($this->cameraWsInternalHost, $this->cameraWsInternalPort, $errno, $errstr, self::CAMERA_WS_TIMEOUT_SECONDS);
        $reachable = is_resource($socket);
        if ($reachable) {
            fclose($socket);
        }

        return ['target' => $target, 'reachable' => $reachable];
    }

    private function isThermorConfigured(): bool
    {
        return $this->atlanticTokenUrl !== ''
            && $this->atlanticTokenAuthorization !== ''
            && $this->atlanticUsername !== ''
            && $this->atlanticPassword !== '';
    }

    /**
     * @return array<string, array{ageSeconds: ?int, staleAfterSeconds: int}>
     */
    private function feedsStatus(\DateTimeImmutable $now): array
    {
        $feeds = [];
        foreach (self::FEEDS as $name => [$key, $staleAfterSeconds]) {
            $updatedAt = $this->keyValues->getUpdatedAt($key);
            $feeds[$name] = [
                'ageSeconds' => $updatedAt === null
                    ? null
                    : max(0, $now->getTimestamp() - $updatedAt->getTimestamp()),
                'staleAfterSeconds' => $staleAfterSeconds,
            ];
        }

        return $feeds;
    }
}
