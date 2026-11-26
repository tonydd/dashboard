<?php

namespace App\Tests\Service;

use App\Service\DashboardFeedService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DashboardFeedServiceTest extends TestCase
{
    public function testWeatherReturnsDataAndKeepsCredentialsInTheServerRequest(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('private-weather-key', $query['appid'] ?? null);
            self::assertSame('48', $query['lat'] ?? null);
            self::assertSame('metric', $query['units'] ?? null);

            return new MockResponse('{"current":{"temp":12}}');
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $feeds = $this->service($client, $logger);

        self::assertSame(['current' => ['temp' => 12]], $feeds->weather());
    }

    public function testWeatherConfigurationRejectsTemplatePlaceholdersAndInvalidUnits(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('An unconfigured weather feed must not make an upstream request.');
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $placeholderKey = new DashboardFeedService(
            $client,
            $logger,
            'https://weather.test/onecall',
            'TO_BE_DEFINED',
            '48',
            '7',
            'metric',
            'fr',
        );
        self::assertFalse($placeholderKey->isWeatherConfigured());
        self::assertNull($placeholderKey->weather());

        $invalidCoordinates = new DashboardFeedService(
            $client,
            $logger,
            'https://weather.test/onecall',
            'private-weather-key',
            'TO_BE_DEFINED',
            '7',
            'metric',
            'fr',
        );
        self::assertFalse($invalidCoordinates->isWeatherConfigured());
        self::assertNull($invalidCoordinates->weather());

        self::assertFalse($this->service($client, $logger, 'metrics-invalid')->isWeatherConfigured());
    }

    public function testWeatherFailureIsLoggedWithoutLoggingTheApiKey(): void
    {
        $client = new MockHttpClient(new MockResponse('upstream error', ['http_code' => 503]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('OpenWeatherMap returned an unsuccessful status.', ['status' => 503]);

        self::assertNull($this->service($client, $logger)->weather());
    }

    public function testInvalidNewsFeedIsLoggedAndReportedAsUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('<rss><channel>'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('News feed returned invalid XML.', self::callback(
                static fn (array $context): bool => ($context['xml_error_count'] ?? 0) > 0
            ));

        self::assertNull($this->service($client, $logger)->news());
    }

    private function service(MockHttpClient $client, LoggerInterface $logger, string $units = 'metrics'): DashboardFeedService
    {
        return new DashboardFeedService(
            $client,
            $logger,
            'https://weather.test/onecall',
            'private-weather-key',
            '48',
            '7',
            $units,
            'fr',
        );
    }
}
