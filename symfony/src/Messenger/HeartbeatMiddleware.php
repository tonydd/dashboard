<?php

namespace App\Messenger;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Records the last time a message went through the bus. The /status screen
 * uses it as a consumer liveness signal: the scheduler emits messages every
 * minute, so a stale heartbeat means nobody is consuming the transports.
 */
final readonly class HeartbeatMiddleware implements MiddlewareInterface
{
    public function __construct(private KeyValueRepository $keyValues)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->keyValues->setValue(
            KeyValueKey::MessengerHeartbeat,
            (new \DateTimeImmutable())->format(DATE_ATOM),
        );

        return $stack->next()->handle($envelope, $stack);
    }
}
