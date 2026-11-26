<?php

namespace App\Service;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;

final readonly class DashboardSettingsService
{
    public const DEFAULT_SLIDESHOW_RETURN_SECONDS = 900;
    public const MIN_SLIDESHOW_RETURN_SECONDS = 60;
    public const MAX_SLIDESHOW_RETURN_SECONDS = 86400;

    public function __construct(private KeyValueRepository $keyValues)
    {
    }

    public function slideshowReturnSeconds(): int
    {
        return self::validateSlideshowReturnSeconds(
            $this->keyValues->getValue(KeyValueKey::DashboardSlideshowReturnSeconds)
        ) ?? self::DEFAULT_SLIDESHOW_RETURN_SECONDS;
    }

    public function setSlideshowReturnSeconds(int $seconds): void
    {
        $validated = self::validateSlideshowReturnSeconds($seconds);
        if ($validated === null) {
            throw new \InvalidArgumentException('Slideshow return duration is outside the allowed range.');
        }

        $this->keyValues->setValue(KeyValueKey::DashboardSlideshowReturnSeconds, $validated);
    }

    public static function validateSlideshowReturnSeconds(mixed $value): ?int
    {
        if (!is_int($value)
            || $value < self::MIN_SLIDESHOW_RETURN_SECONDS
            || $value > self::MAX_SLIDESHOW_RETURN_SECONDS
        ) {
            return null;
        }

        return $value;
    }
}
