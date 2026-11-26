<?php

namespace App\Service;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;

/**
 * Image de fond personnalisée de l'écran /tablet, stockée dans KeyValue
 * soit comme URL externe, soit comme image uploadée encodée en base64.
 */
final readonly class TabletBackgroundService
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private KeyValueRepository $keyValues)
    {
    }

    /**
     * @return array{type: 'url', url: string}|array{type: 'data', mimeType: string, data: string}|null
     */
    public function get(): ?array
    {
        $value = $this->keyValues->getValue(KeyValueKey::TabletBackground);
        if (!is_array($value)) {
            return null;
        }

        if (($value['type'] ?? null) === 'url'
            && is_string($value['url'] ?? null)
            && self::isValidUrl($value['url'])
        ) {
            return ['type' => 'url', 'url' => $value['url']];
        }

        if (($value['type'] ?? null) === 'data'
            && is_string($value['mimeType'] ?? null)
            && in_array($value['mimeType'], self::ALLOWED_MIME_TYPES, true)
            && is_string($value['data'] ?? null)
            && base64_decode($value['data'], true) !== false
        ) {
            return ['type' => 'data', 'mimeType' => $value['mimeType'], 'data' => $value['data']];
        }

        return null;
    }

    public function setUrl(string $url): void
    {
        if (!self::isValidUrl($url)) {
            throw new \InvalidArgumentException('The background URL must be an absolute http(s) URL.');
        }

        $this->keyValues->setValue(KeyValueKey::TabletBackground, ['type' => 'url', 'url' => $url]);
    }

    public function setImage(string $binary, string $mimeType): void
    {
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('The background image must be a JPEG, PNG, WebP or GIF file.');
        }

        if ($binary === '' || strlen($binary) > self::MAX_IMAGE_BYTES) {
            throw new \InvalidArgumentException(sprintf('The background image must be between 1 and %d bytes.', self::MAX_IMAGE_BYTES));
        }

        $this->keyValues->setValue(KeyValueKey::TabletBackground, [
            'type' => 'data',
            'mimeType' => $mimeType,
            'data' => base64_encode($binary),
        ]);
    }

    public function clear(): void
    {
        $this->keyValues->setValue(KeyValueKey::TabletBackground, null);
    }

    public static function isValidUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
