<?php

namespace App\Service;

use JsonException;

/**
 * Issues and validates short-lived tokens used to authenticate
 * camera WebSocket connections outside of the PHP session store.
 */
final class CameraTokenService
{
    public const ROLE_PUBLISHER = 'publisher';
    public const ROLE_VIEWER = 'viewer';

    // Keeping the TTL short limits the impact of a token leaking into proxy logs.
    public const TTL_SECONDS = 300;

    public function __construct(
        private readonly string $appSecret,
    ) {
    }

    /**
     * @return array{token: string, expiresAt: int}
     */
    public function issue(string $role): array
    {
        if (!\in_array($role, [self::ROLE_PUBLISHER, self::ROLE_VIEWER], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported camera websocket role "%s".', $role));
        }

        $expiresAt = time() + self::TTL_SECONDS;
        $payload = base64_encode((string) json_encode(['role' => $role, 'exp' => $expiresAt], JSON_THROW_ON_ERROR));

        return [
            'token' => $payload.'.'.$this->signature($payload),
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Returns the connection role for a valid, unexpired token, null otherwise.
     */
    public function resolve(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;
        if (!hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        try {
            $data = json_decode(base64_decode($payload, true), true, 2, JSON_THROW_ON_ERROR);
        } catch (\ValueError|JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $role = $data['role'] ?? null;
        $expiresAt = $data['exp'] ?? null;

        return \is_string($role)
            && \in_array($role, [self::ROLE_PUBLISHER, self::ROLE_VIEWER], true)
            && \is_int($expiresAt)
            && $expiresAt >= time()
            ? $role
            : null;
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->appSecret);
    }
}
