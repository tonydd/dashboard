<?php

namespace App\Tests\Service;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use App\Service\DashboardSettingsService;
use PHPUnit\Framework\TestCase;

final class DashboardSettingsServiceTest extends TestCase
{
    public function testMissingOrInvalidStoredValueUsesSafeDefault(): void
    {
        $repository = $this->createMock(KeyValueRepository::class);
        $repository->expects(self::exactly(2))
            ->method('getValue')
            ->with(KeyValueKey::DashboardSlideshowReturnSeconds)
            ->willReturnOnConsecutiveCalls(null, 'not-an-integer');

        $settings = new DashboardSettingsService($repository);

        self::assertSame(900, $settings->slideshowReturnSeconds());
        self::assertSame(900, $settings->slideshowReturnSeconds());
    }

    public function testReadsAndPersistsValidatedDuration(): void
    {
        $repository = $this->createMock(KeyValueRepository::class);
        $repository->expects(self::once())
            ->method('getValue')
            ->with(KeyValueKey::DashboardSlideshowReturnSeconds)
            ->willReturn(1200);
        $repository->expects(self::once())
            ->method('setValue')
            ->with(KeyValueKey::DashboardSlideshowReturnSeconds, 1200);

        $settings = new DashboardSettingsService($repository);
        self::assertSame(1200, $settings->slideshowReturnSeconds());
        $settings->setSlideshowReturnSeconds(1200);
    }

    public function testRejectsValuesOutsideTheAllowedRangeAndNonIntegers(): void
    {
        foreach ([59, 86401, 900.0, '900', null] as $value) {
            self::assertNull(DashboardSettingsService::validateSlideshowReturnSeconds($value));
        }

        self::assertSame(60, DashboardSettingsService::validateSlideshowReturnSeconds(60));
        self::assertSame(86400, DashboardSettingsService::validateSlideshowReturnSeconds(86400));
    }
}
