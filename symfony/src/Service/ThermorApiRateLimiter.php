<?php

namespace App\Service;

use App\Enum\KeyValueKey;
use App\Exception\ThermorRateLimitedException;
use App\Repository\KeyValueRepository;
use Psr\Log\LoggerInterface;

/**
 * Global call budget shared by every Thermor / Overkiz API call site, past
 * and future. A token bucket persisted in KeyValue so every worker (the
 * scheduler consumer, web requests, future services) observes the same
 * budget and the account stays well below the quotas enforced by the
 * Atlantic / Overkiz clouds (their limits are undocumented and reports
 * mention multi-hour lockouts once exceeded).
 */
final class ThermorApiRateLimiter
{
    private const BUCKET_CAPACITY = 10;      // burst headroom above the steady 1 call/min
    private const REFILL_SECONDS = 30;       // sustained ceiling: 2 calls/min
    private const BACKOFF_BASE_SECONDS = 60; // 60s * 2^(attempt-1)
    private const BACKOFF_MAX_SECONDS = 1800;

    public function __construct(
        private readonly KeyValueRepository $keyValueRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Consume one call from the budget or throw ThermorRateLimitedException.
     * Every Outbound Thermor/Overkiz HTTP call must go through this.
     */
    public function acquire(): void
    {
        $state = $this->state();
        $now = $this->now();

        if ($state['backoffUntil'] !== null && $now < $state['backoffUntil']) {
            throw new ThermorRateLimitedException($state['backoffUntil']->getTimestamp() - $now->getTimestamp());
        }

        $updatedAt = $state['updatedAt'] ?? $now;
        $elapsed = max(0, $now->getTimestamp() - $updatedAt->getTimestamp());
        $tokens = min(self::BUCKET_CAPACITY, $state['tokens'] + $elapsed / self::REFILL_SECONDS);

        if ($tokens < 1) {
            throw new ThermorRateLimitedException((int) ceil((1 - $tokens) * self::REFILL_SECONDS));
        }

        $this->save([
            'tokens' => $tokens - 1,
            'updatedAt' => $now,
            'backoffUntil' => $state['backoffUntil'],
            'backoffAttempts' => $state['backoffAttempts'],
        ]);
    }

    /**
     * Record that the remote cloud refused a call (429 / QUOTA_EXCEEDED)
     * and schedule an exponentially growing pause for all call sites.
     */
    public function penalize(): void
    {
        $state = $this->state();
        $attempts = $state['backoffAttempts'] + 1;
        $delay = min(self::BACKOFF_BASE_SECONDS * 2 ** ($attempts - 1), self::BACKOFF_MAX_SECONDS);
        $until = $this->now()->modify(sprintf('+%d seconds', $delay));

        $this->logger->warning(sprintf(
            'Thermor API quota reported by the cloud; pausing all water-heater calls until %s.',
            $until->format(\DateTimeInterface::ATOM),
        ));

        $this->save([
            'tokens' => $state['tokens'],
            'updatedAt' => $this->now(),
            'backoffUntil' => $until,
            'backoffAttempts' => $attempts,
        ]);
    }

    /**
     * Seconds left in the current backoff pause (0 when none is active).
     */
    public function backoffRemainingSeconds(): int
    {
        $state = $this->state();
        if ($state['backoffUntil'] === null) {
            return 0;
        }

        return max(0, $state['backoffUntil']->getTimestamp() - $this->now()->getTimestamp());
    }

    /**
     * Record a successful round trip: the backoff, if any, is lifted.
     */
    public function recordSuccess(): void
    {
        $state = $this->state();
        if ($state['backoffUntil'] === null && $state['backoffAttempts'] === 0) {
            return;
        }

        $this->save([
            'tokens' => $state['tokens'],
            'updatedAt' => $this->now(),
            'backoffUntil' => null,
            'backoffAttempts' => 0,
        ]);
    }

    /**
     * @return array{tokens: float, updatedAt: ?\DateTimeImmutable, backoffUntil: ?\DateTimeImmutable, backoffAttempts: int}
     */
    private function state(): array
    {
        $raw = $this->keyValueRepository->getValue(KeyValueKey::ThermorApiRateLimit);
        $raw = is_array($raw) ? $raw : [];

        return [
            'tokens' => is_numeric($raw['tokens'] ?? null) ? max(0.0, (float) $raw['tokens']) : self::BUCKET_CAPACITY,
            'updatedAt' => $this->date($raw['updatedAt'] ?? null),
            'backoffUntil' => $this->date($raw['backoffUntil'] ?? null),
            'backoffAttempts' => is_int($raw['backoffAttempts'] ?? null) ? $raw['backoffAttempts'] : 0,
        ];
    }

    /**
     * @param array{tokens: float, updatedAt: \DateTimeImmutable, backoffUntil: ?\DateTimeImmutable, backoffAttempts: int} $state
     */
    private function save(array $state): void
    {
        $this->keyValueRepository->setValue(KeyValueKey::ThermorApiRateLimit, [
            'tokens' => round($state['tokens'], 2),
            'updatedAt' => $state['updatedAt']->format(\DateTimeInterface::ATOM),
            'backoffUntil' => $state['backoffUntil']?->format(\DateTimeInterface::ATOM),
            'backoffAttempts' => $state['backoffAttempts'],
        ]);
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);

        return $date === false ? null : $date;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
