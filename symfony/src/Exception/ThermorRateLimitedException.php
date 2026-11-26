<?php

namespace App\Exception;

/**
 * Raised when the global Thermor/Overkiz API budget is exhausted or the
 * remote cloud reported a quota (429 / QUOTA_EXCEEDED) and a backoff is
 * active. Call sites should skip their work and retry later.
 */
final class ThermorRateLimitedException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $message = 'Thermor API rate limit reached.',
    ) {
        parent::__construct($message);
    }
}
