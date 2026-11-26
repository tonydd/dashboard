<?php

namespace App\Tests\Service;

use App\Enum\KeyValueKey;
use App\Exception\ThermorRateLimitedException;
use App\Repository\KeyValueRepository;
use App\Service\ThermorApiRateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ThermorApiRateLimiterTest extends TestCase
{
    private array $storage = [];

    private KeyValueRepository $repository;

    protected function setUp(): void
    {
        $this->storage = [];
        $this->repository = $this->createMock(KeyValueRepository::class);
        $this->repository->method('getValue')
            ->willReturnCallback(fn (KeyValueKey $key): mixed => $this->storage[$key->value] ?? null);
        $this->repository->method('setValue')
            ->willReturnCallback(function (KeyValueKey $key, mixed $value): void {
                $this->storage[$key->value] = $value;
            });
    }

    private function limiter(): ThermorApiRateLimiter
    {
        return new ThermorApiRateLimiter($this->repository, $this->createMock(LoggerInterface::class));
    }

    public function testAllowsBurstUpToCapacityThenBlocks(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 10; ++$i) {
            $limiter->acquire();
        }

        try {
            $limiter->acquire();
            $this->fail('The 11th consecutive call should be rejected.');
        } catch (ThermorRateLimitedException $e) {
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
            $this->assertLessThanOrEqual(30, $e->retryAfterSeconds);
        }
    }

    public function testRefillsTokensBasedOnElapsedTime(): void
    {
        // Bucket emptied 10 minutes ago: the refill is capped at capacity.
        $this->storage['thermor_api_rate_limit'] = [
            'tokens' => 0,
            'updatedAt' => (new \DateTimeImmutable('-10 minutes'))->format(\DATE_ATOM),
            'backoffUntil' => null,
            'backoffAttempts' => 0,
        ];

        $limiter = $this->limiter();
        for ($i = 0; $i < 10; ++$i) {
            $limiter->acquire();
        }

        try {
            $limiter->acquire();
            $this->fail('The burst budget should be exhausted.');
        } catch (ThermorRateLimitedException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testBackoffBlocksAllCallsUntilDeadline(): void
    {
        $this->storage['thermor_api_rate_limit'] = [
            'tokens' => 10,
            'updatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'backoffUntil' => (new \DateTimeImmutable('+5 minutes'))->format(\DATE_ATOM),
            'backoffAttempts' => 2,
        ];

        try {
            $this->limiter()->acquire();
            $this->fail('A call during the backoff window should be rejected.');
        } catch (ThermorRateLimitedException $e) {
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
            $this->assertLessThanOrEqual(300, $e->retryAfterSeconds);
        }
    }

    public function testPenalizeSchedulesExponentialBackoff(): void
    {
        $limiter = $this->limiter();

        $limiter->penalize();
        $first = $this->storage['thermor_api_rate_limit'];
        $this->assertSame(1, $first['backoffAttempts']);
        $this->assertNotNull($first['backoffUntil']);

        $limiter->penalize();
        $second = $this->storage['thermor_api_rate_limit'];
        $this->assertSame(2, $second['backoffAttempts']);

        $firstDelay = strtotime($first['backoffUntil']) - time();
        $secondDelay = strtotime($second['backoffUntil']) - time();
        $this->assertGreaterThan($firstDelay, $secondDelay);
    }

    public function testRecordSuccessLiftsTheBackoff(): void
    {
        $this->storage['thermor_api_rate_limit'] = [
            'tokens' => 5,
            'updatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'backoffUntil' => (new \DateTimeImmutable('+5 minutes'))->format(\DATE_ATOM),
            'backoffAttempts' => 3,
        ];

        $limiter = $this->limiter();
        $limiter->recordSuccess();

        $this->limiter()->acquire();
        $this->assertNull($this->storage['thermor_api_rate_limit']['backoffUntil']);
        $this->assertSame(0, $this->storage['thermor_api_rate_limit']['backoffAttempts']);
        $this->assertSame(4.0, $this->storage['thermor_api_rate_limit']['tokens']);
    }
}
