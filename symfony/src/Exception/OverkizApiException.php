<?php

namespace App\Exception;

/**
 * HTTP error returned by an Overkiz/Atlantic endpoint, carrying the status
 * code so callers can distinguish an expired session (401/403) from other
 * failures and recycle the session.
 */
final class OverkizApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $responseBody = '',
    ) {
        parent::__construct($message);
    }

    public function isSessionError(): bool
    {
        return $this->statusCode === 401 || $this->statusCode === 403;
    }
}
