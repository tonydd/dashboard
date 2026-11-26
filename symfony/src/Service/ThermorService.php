<?php

namespace App\Service;

use App\Entity\WaterHeaterStat;
use App\Repository\KeyValueRepository;
use App\Repository\WaterHeaterStatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ThermorService
{
    public function __construct(
        private EntityManagerInterface     $entityManager,
        private CacheInterface             $cache,
        private KeyValueRepository $keyValueRepository,
        private SerializerInterface        $serializer,
        private WaterHeaterStatRepository  $waterHeaterStatRepository,
        private Filesystem                 $filesystem,
        private HttpClientInterface        $httpClient,
        private LoggerInterface            $logger,
        private ThermorStateParser         $stateParser,
        private string                     $atlanticTokenUrl = '',
        private string                     $atlanticTokenAuthorization = '',
        private string                     $atlanticUsername = '',
        private string                     $atlanticPassword = '',
        private string                     $magellanTokenUrl = '',
        private string                     $overkizTokenUrl = '',
        private string                     $overkizAuthorization = '',
        private string                     $overkizEndpointUrl = '',
        private string                     $overkizEndpointCommandUrl = '',
        private string                     $appTimezone = 'Europe/Paris',
    )
    {
    }

    public function setAbsenceMode(bool $enabled): array
    {
        $result = $this->applyAbsenceMode($enabled, false);
        unset($result['changed']);

        return $result;
    }

    public function reconcileAbsenceMode(bool $enabled): array
    {
        return $this->applyAbsenceMode($enabled, true);
    }

    private function applyAbsenceMode(bool $enabled, bool $skipIfAlreadyInRequestedState): array
    {
        $atlanticToken = $this->atlanticToken();
        $magellanToken = $this->magellanToken($atlanticToken);
        $cookieValue = $this->endUserAPILogin($magellanToken);
        $setup = $this->setup($cookieValue);

        $heater = null;
        foreach ($setup['devices'] ?? [] as $device) {
            if (($device['controllableName'] ?? null) === 'modbuslink:AtlanticDomesticHotWaterProductionMBLComponent') {
                $heater = $device;
                break;
            }
        }

        if ($heater === null || !isset($heater['deviceURL'])) {
            throw new \RuntimeException('The Overkiz water heater was not found in the setup response.');
        }
        if (($heater['available'] ?? false) !== true) {
            throw new \RuntimeException('The Overkiz water heater is unavailable.');
        }

        if ($skipIfAlreadyInRequestedState) {
            $states = $heater['states'] ?? [];
            if (!is_array($states)) {
                throw new \RuntimeException('The Overkiz water-heater state list is invalid.');
            }

            $absenceMode = null;
            foreach ($states as $state) {
                if (!is_array($state)) {
                    throw new \RuntimeException('The Overkiz water-heater state list is invalid.');
                }

                if (($state['name'] ?? null) === 'modbuslink:DHWAbsenceModeState'
                    && is_string($state['value'] ?? null)
                ) {
                    $absenceMode = $state['value'];
                    break;
                }
            }

            if ($absenceMode === null) {
                throw new \RuntimeException('The Overkiz setup did not report the water-heater absence mode.');
            }

            if (($absenceMode !== 'off') === $enabled) {
                return [
                    'enabled' => $enabled,
                    'validUntil' => null,
                    'changed' => false,
                ];
            }
        }

        $validUntil = null;
        if ($enabled) {
            $start = new \DateTimeImmutable('now', new \DateTimeZone($this->appTimezone));
            $end = $start->modify('+1 year');
            $startParameters = $this->dateParameters($start);

            $commands = [
                ['name' => 'setDateTime', 'parameters' => [$startParameters]],
                ['name' => 'setAbsenceStartDate', 'parameters' => [$startParameters]],
                ['name' => 'setAbsenceEndDate', 'parameters' => [$this->dateParameters($end)]],
                ['name' => 'setAbsenceMode', 'parameters' => ['prog']],
            ];
            $validUntil = $end->format(\DateTimeInterface::ATOM);
        } else {
            $commands = [
                ['name' => 'setAbsenceMode', 'parameters' => ['off']],
            ];
        }

        $deviceCommands = $heater['definition']['commands'] ?? [];
        if (!is_array($deviceCommands)) {
            throw new \RuntimeException('The Overkiz water-heater command list is invalid.');
        }
        $availableCommands = array_column($deviceCommands, 'commandName');
        foreach ($commands as $command) {
            if (!in_array($command['name'], $availableCommands, true)) {
                throw new \RuntimeException(sprintf('The Overkiz water heater does not support "%s".', $command['name']));
            }
        }

        foreach ($commands as $command) {
            $this->execCommand($cookieValue, $heater['deviceURL'], $command['name'], $command['parameters']);
        }

        return [
            'enabled' => $enabled,
            'validUntil' => $validUntil,
            'changed' => true,
        ];
    }

    private function dateParameters(\DateTimeImmutable $date): array
    {
        return [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'day' => (int) $date->format('j'),
            'hour' => (int) $date->format('G'),
            'minute' => (int) $date->format('i'),
            'second' => (int) $date->format('s'),
            'weekday' => (int) $date->format('N'),
        ];
    }

    public function update(): void
    {
        $atlanticToken = $this->atlanticToken();
        $magellanToken = $this->magellanToken($atlanticToken);
        $cookieValue = $this->endUserAPILogin($magellanToken);
        $setup = $this->setup($cookieValue);

        $this->filesystem->dumpFile('./var/files/thermorData.json', json_encode($setup, JSON_PRETTY_PRINT));

        $out = $this->stateParser->extract($setup);
        if ($out === null) {
            $this->logger->warning('Skipping Thermor history update because the Overkiz response is missing required water-heater states.');

            return;
        }

        $waterHeaterStat = $this->waterHeaterStatRepository->findLast();
        if ($waterHeaterStat
            && $waterHeaterStat->getWh() === $out['electricalConsumption']
            && $waterHeaterStat->getWaterTemperature() === $out['waterTemperature']
            && $waterHeaterStat->getAvailable40Degrees() === $out['V40Capacity']
            && $waterHeaterStat->isHeatingState() === $out['heatingState']
            && $waterHeaterStat->isAbsenceMode() === $out['absenceMode']
        ) {
            $waterHeaterStat->setTs(new \DateTime());
        } else {
            $waterHeaterStat = new WaterHeaterStat();
            $waterHeaterStat->setHeatingState($out['heatingState']);
            $waterHeaterStat->setWaterTemperature($out['waterTemperature']);
            $waterHeaterStat->setAvailable40Degrees($out['V40Capacity']);
            $waterHeaterStat->setWh($out['electricalConsumption']);
            $waterHeaterStat->setTs(new \DateTime());
            $waterHeaterStat->setAbsenceMode($out['absenceMode']);
            $this->entityManager->persist($waterHeaterStat);
        }

        $this->entityManager->flush();

        $this->keyValueRepository->setValue(
            WaterHeaterStat::currentValueKey(),
            $this->serializer->normalize($waterHeaterStat),
        );
    }

    private function atlanticToken(): string
    {
        return $this->cache->get('atlanticToken', function (ItemInterface $item): string {
            $ch = curl_init();
            if ($ch === false) {
                throw new \RuntimeException('Could not initialize the Atlantic authentication request.');
            }

            curl_setopt($ch, CURLOPT_URL, $this->atlanticTokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: ' . $this->atlanticTokenAuthorization,
            ]);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                http_build_query([
                    'grant_type' => 'password',
                    'username' => $this->atlanticUsername,
                    'password' => $this->atlanticPassword,
                ])
            );

            $response = $this->finishCurlRequest($ch, curl_exec($ch), 'Atlantic authentication');
            $data = json_decode($response, true);
            if (!is_array($data) || !isset($data['access_token'], $data['expires_in'])) {
                throw new \RuntimeException('Atlantic authentication response did not contain an access token.');
            }

            $item->expiresAfter(max(1, (int) $data['expires_in'] - 6));
            return $data['access_token'];
        });
    }

    private function magellanToken(string $atlanticToken): string
    {
        return $this->cache->get('magellanToken', function (ItemInterface $item) use ($atlanticToken): string {
            $ch = curl_init();
            if ($ch === false) {
                throw new \RuntimeException('Could not initialize the Magellan authentication request.');
            }

            curl_setopt($ch, CURLOPT_URL, $this->magellanTokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $atlanticToken,
            ]);

            $response = $this->finishCurlRequest($ch, curl_exec($ch), 'Magellan authentication');
            $jwt = trim($response, "\" \r\n");
            if ($jwt === '') {
                throw new \RuntimeException('Magellan authentication response did not contain a token.');
            }

            $item->expiresAfter(300);
            return $jwt;
        });
    }

    private function endUserAPILogin(string $magellanToken): string
    {
        return $this->cache->get('overkizToken', function (ItemInterface $item) use ($magellanToken): string {
            $ch = curl_init();
            if ($ch === false) {
                throw new \RuntimeException('Could not initialize the Overkiz login request.');
            }

            curl_setopt($ch, CURLOPT_URL, $this->overkizTokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: ' . $this->overkizAuthorization
            ]);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                http_build_query([
                    'jwt' => $magellanToken
                ])
            );
            $sessionCookie = null;
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$sessionCookie) {
                if (preg_match('/^Set-Cookie:\s*JSESSIONID=([^;]*)/i', trim($headerLine), $cookie) === 1) {
                    $sessionCookie = $cookie[1];
                }
                return strlen($headerLine); // Needed by curl
            });

            $this->finishCurlRequest($ch, curl_exec($ch), 'Overkiz login');
            if ($sessionCookie === null || $sessionCookie === '') {
                throw new \RuntimeException('Overkiz login response did not contain a session cookie.');
            }
            // Overkiz sessions are only valid with the full JSESSIONID as
            // received (including the gateway prefix); stripping the prefix
            // causes HTTP 401 on subsequent setup and command requests.
            $cookieValue = $sessionCookie;

            $item->expiresAfter(60 * 15);
            return $cookieValue;
        });
    }

    private function setup(string $cookieValue): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Could not initialize the Overkiz setup request.');
        }

        curl_setopt($ch, CURLOPT_URL, $this->overkizEndpointUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Host: ha110-1.overkiz.com',
            'Connection: keep-alive',
            'Cache-Control: no-cache',
            'Cookie: JSESSIONID=' . $cookieValue,
        ]);

        $response = $this->finishCurlRequest($ch, curl_exec($ch), 'Overkiz setup');
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Overkiz setup returned invalid JSON.');
        }

        return $data;
    }

    private function finishCurlRequest(\CurlHandle $ch, string|false $response, string $requestName): string
    {
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false) {
            throw new \RuntimeException(sprintf('%s request failed: %s', $requestName, $error));
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('%s request failed with HTTP %d.', $requestName, $status));
        }

        return $response;
    }

    private function execCommand(string $cookieValue, string $deviceUrl, string $commandName, array $parameters): array
    {
        $response = $this->httpClient->request('POST', $this->overkizEndpointCommandUrl, [
            'headers' => [
                'Host' => 'ha110-1.overkiz.com',
                'Connection' => 'keep-alive',
                'Cache-Control' => 'no-cache',
                'Cookie' => 'JSESSIONID=' . $cookieValue,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'json' => [
                'label' => 'dashboard',
                'actions' => [[
                    'deviceURL' => $deviceUrl,
                    'commands' => [[
                        'name' => $commandName,
                        'parameters' => $parameters,
                    ]],
                ]],
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Overkiz command "%s" failed with HTTP %d.', $commandName, $status));
        }

        $responseData = $response->toArray(false);
        if (!isset($responseData['execId'])) {
            throw new \RuntimeException(sprintf('Overkiz did not acknowledge command "%s".', $commandName));
        }
        return $responseData;
    }
}