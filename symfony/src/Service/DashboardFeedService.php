<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DashboardFeedService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $openWeatherApiUrl,
        private string $openWeatherApiKey,
        private string $openWeatherLat,
        private string $openWeatherLon,
        private string $openWeatherUnits,
        private string $openWeatherLang,
    ) {
    }

    public function isWeatherConfigured(): bool
    {
        $apiKey = trim($this->openWeatherApiKey);
        $latitude = filter_var(trim($this->openWeatherLat), FILTER_VALIDATE_FLOAT);
        $longitude = filter_var(trim($this->openWeatherLon), FILTER_VALIDATE_FLOAT);

        return $apiKey !== ''
            && strtoupper($apiKey) !== 'TO_BE_DEFINED'
            && $latitude !== false
            && $latitude >= -90
            && $latitude <= 90
            && $longitude !== false
            && $longitude >= -180
            && $longitude <= 180
            && $this->normalizedWeatherUnits() !== null;
    }

    public function weather(): ?array
    {
        if (!$this->isWeatherConfigured()) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->openWeatherApiUrl, [
                'query' => [
                    'lat' => $this->openWeatherLat,
                    'lon' => $this->openWeatherLon,
                    'appid' => $this->openWeatherApiKey,
                    'units' => $this->normalizedWeatherUnits(),
                    'lang' => $this->openWeatherLang,
                ],
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('OpenWeatherMap returned an unsuccessful status.', ['status' => $status]);

                return null;
            }

            $data = $response->toArray(false);
            if (!isset($data['current']) || !is_array($data['current'])) {
                $this->logger->warning('OpenWeatherMap returned an unexpected response shape.');

                return null;
            }

            return $data;
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('OpenWeatherMap request failed.', ['error_type' => $exception::class]);

            return null;
        }
    }

    public function news(): ?array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://www.soultzsousforets.fr/rss/actus.php', [
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('News feed returned an unsuccessful status.', ['status' => $status]);

                return null;
            }

            $previousLibxmlSetting = libxml_use_internal_errors(true);
            try {
                $xml = simplexml_load_string($response->getContent(false), options: LIBXML_NONET);
                $xmlErrors = libxml_get_errors();
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousLibxmlSetting);
            }

            if ($xml === false) {
                $this->logger->warning('News feed returned invalid XML.', ['xml_error_count' => count($xmlErrors)]);

                return null;
            }

            $news = [];
            foreach ($xml->channel->item as $item) {
                $news[] = [
                    'title' => trim((string) $item->title),
                    'link' => trim((string) $item->link),
                ];
                if (count($news) >= 5) {
                    break;
                }
            }

            return $news;
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('News feed request failed.', ['error_type' => $exception::class]);

            return null;
        }
    }

    private function normalizedWeatherUnits(): ?string
    {
        return match (strtolower(trim($this->openWeatherUnits))) {
            'metric', 'metrics' => 'metric',
            'imperial' => 'imperial',
            'standard' => 'standard',
            default => null,
        };
    }
}
