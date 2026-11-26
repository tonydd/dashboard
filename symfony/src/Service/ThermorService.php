<?php

namespace App\Service;

use App\Entity\WaterHeaterStat;
use App\Exception\OverkizApiException;
use App\Exception\ThermorRateLimitedException;
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
    private const SETUP_CACHE_KEY = 'thermorSetupData';
    private const SETUP_CACHE_TTL = 55;

    // Parameter vocabularies as used by the Overkiz cloud for this device
    // (mirrors the pyoverkiz / Home-Assistant command parameters). Values
    // marked "unconfirmed" have never been sent for real yet.
    private const DHW_MODES = ['autoMode', 'manualEcoActive', 'manualEcoInactive'];
    private const SMART_GRID_OPTIONS = ['deactive', 'active']; // 'active' unconfirmed
    private const OPERATING_RANGES = ['pac24h_elec24h', 'pacProg_elecProg'];
    private const TARGET_TEMPERATURE_MIN = 50.0; // core:MinimalTemperatureManualModeState
    private const TARGET_TEMPERATURE_MAX = 65.0; // core:MaximalTemperatureManualModeState
    private const EXPECTED_NUMBER_OF_SHOWER_MIN = 1; // core:MinimalShowerManualModeState
    private const EXPECTED_NUMBER_OF_SHOWER_MAX = 5; // core:MaximalShowerManualModeState
    private const HEATER_DEVICE = 'modbuslink:AtlanticDomesticHotWaterProductionMBLComponent';

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
        private ThermorApiRateLimiter      $rateLimiter,
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

    /**
     * Every user-facing water-heater action below validates its input,
     * then dispatches one exec call (possibly several commands batched)
     * through the shared rate-limited HTTP plumbing.
     */

    public function setTargetTemperature(float $temperature): array
    {
        if ($temperature < self::TARGET_TEMPERATURE_MIN || $temperature > self::TARGET_TEMPERATURE_MAX) {
            throw new \InvalidArgumentException(sprintf(
                'The water-heater target temperature must be between %.0f and %.0f degrees.',
                self::TARGET_TEMPERATURE_MIN,
                self::TARGET_TEMPERATURE_MAX,
            ));
        }

        return $this->dispatchAction([
            ['name' => 'setTargetDHWTemperature', 'parameters' => [$temperature]],
        ]);
    }

    public function setDhwMode(string $mode): array
    {
        if (!in_array($mode, self::DHW_MODES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown water-heater DHW mode "%s".', $mode));
        }

        return $this->dispatchAction([
            ['name' => 'setDHWMode', 'parameters' => [$mode]],
        ]);
    }

    public function setBoostMode(bool $enabled): array
    {
        return $this->dispatchAction([
            ['name' => 'setBoostMode', 'parameters' => [$enabled ? 'on' : 'off']],
        ]);
    }

    public function setAntiLegionellosis(bool $enabled): array
    {
        // modbuslink:AntiLegionellosisState is reported as an integer; the
        // exact set-parameter format has not been confirmed on a live call yet.
        return $this->dispatchAction([
            ['name' => 'setAntiLegionellosis', 'parameters' => [$enabled ? 1 : 0]],
        ]);
    }

    public function setSmartGridOption(string $option): array
    {
        if (!in_array($option, self::SMART_GRID_OPTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown water-heater smart-grid option "%s".', $option));
        }

        return $this->dispatchAction([
            ['name' => 'setSmartGridOption', 'parameters' => [$option]],
        ]);
    }

    public function setOperatingRange(string $range): array
    {
        if (!in_array($range, self::OPERATING_RANGES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown water-heater operating range "%s".', $range));
        }

        return $this->dispatchAction([
            ['name' => 'setOperatingRange', 'parameters' => [$range]],
        ]);
    }

    public function setExpectedNumberOfShower(int $count): array
    {
        if ($count < self::EXPECTED_NUMBER_OF_SHOWER_MIN || $count > self::EXPECTED_NUMBER_OF_SHOWER_MAX) {
            throw new \InvalidArgumentException(sprintf(
                'The expected number of showers must be between %d and %d.',
                self::EXPECTED_NUMBER_OF_SHOWER_MIN,
                self::EXPECTED_NUMBER_OF_SHOWER_MAX,
            ));
        }

        return $this->dispatchAction([
            ['name' => 'setExpectedNumberOfShower', 'parameters' => [$count]],
        ]);
    }

    public function setProgrammingSlots(array $slots): array
    {
        $this->assertProgrammingSlots($slots);

        return $this->dispatchAction([
            ['name' => 'setProgrammingSlots', 'parameters' => [$slots]],
        ]);
    }

    public function synchronizeDateTime(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->appTimezone));

        return $this->dispatchAction([
            ['name' => 'setDateTime', 'parameters' => [$this->dateParameters($now)]],
        ]);
    }

    private function assertProgrammingSlots(array $slots): void
    {
        if ($slots === []) {
            throw new \InvalidArgumentException('The water-heater programming slots must not be empty.');
        }

        foreach ($slots as $name => $slot) {
            if (!is_string($name) || !preg_match('/^slot\d+$/', $name)
                || !is_array($slot)
                || !isset($slot['start'], $slot['end'])
                || !is_string($slot['start']) || !is_string($slot['end'])
                || !preg_match('/^\d{2}:\d{2}$/', $slot['start'])
                || !preg_match('/^\d{2}:\d{2}$/', $slot['end'])
            ) {
                throw new \InvalidArgumentException(
                    'The water-heater programming slots must look like {"slot1": {"start": "08:00", "end": "20:00"}}.',
                );
            }
        }
    }

    /**
     * One setup read + one exec call carrying every command of the action.
     */
    private function dispatchAction(array $commands): array
    {
        $this->runDeviceCommands($this->findHeater($this->fetchSetup()), $commands);

        return ['accepted' => true];
    }

    private function findHeater(array $setup): array
    {
        foreach ($setup['devices'] ?? [] as $device) {
            if (!is_array($device)
                || ($device['controllableName'] ?? null) !== self::HEATER_DEVICE
                || !isset($device['deviceURL'])
            ) {
                continue;
            }

            if (($device['available'] ?? false) !== true) {
                throw new \RuntimeException('The Overkiz water heater is unavailable.');
            }

            return $device;
        }

        throw new \RuntimeException('The Overkiz water heater was not found in the setup response.');
    }

    private function runDeviceCommands(array $heater, array $commands): void
    {
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

        $cookieValue = $this->endUserAPILogin($this->magellanToken($this->atlanticToken()));
        try {
            $this->runCommands($cookieValue, $heater['deviceURL'], $commands);
        } catch (OverkizApiException $e) {
            if (!$e->isSessionError()) {
                throw $e;
            }
            // The session died mid-way; commands are idempotent, re-apply
            // them all with a fresh session.
            $cookieValue = $this->endUserAPILogin($this->magellanToken($this->atlanticToken()), true);
            $this->runCommands($cookieValue, $heater['deviceURL'], $commands);
        }
        $this->cache->delete(self::SETUP_CACHE_KEY);
    }

    private function applyAbsenceMode(bool $enabled, bool $skipIfAlreadyInRequestedState): array
    {
        $heater = $this->findHeater($this->fetchSetup());

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

        $this->runDeviceCommands($heater, $commands);

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
        $setup = $this->fetchSetup();

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
            $this->rateLimiter->acquire();
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
            $this->rateLimiter->acquire();
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

    private function endUserAPILogin(string $magellanToken, bool $forceFresh = false): string
    {
        if ($forceFresh) {
            $this->cache->delete('overkizToken');
            $this->cache->delete('magellanToken');
        }

        return $this->cache->get('overkizToken', function (ItemInterface $item) use ($magellanToken): string {
            $this->rateLimiter->acquire();
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

            $item->expiresAfter(60 * 30);
            return $cookieValue;
        });
    }

    /**
     * Shared by the polling and schedule-reconciliation call sites: within
     * the cache TTL both consumers reuse a single setup call.
     */
    private function fetchSetup(): array
    {
        try {
            return $this->setup($this->endUserAPILogin($this->magellanToken($this->atlanticToken())));
        } catch (OverkizApiException $e) {
            if (!$e->isSessionError()) {
                throw $e;
            }
            // The cached session was rejected; force a fresh one and retry once.
            return $this->setup($this->endUserAPILogin($this->magellanToken($this->atlanticToken()), true), false);
        }
    }

    private function setup(string $cookieValue, bool $useCache = true): array
    {
        if ($useCache) {
            return $this->cache->get(self::SETUP_CACHE_KEY, function (ItemInterface $item) use ($cookieValue): array {
                $item->expiresAfter(self::SETUP_CACHE_TTL);

                return $this->setup($cookieValue, false);
            });
        }

        $this->rateLimiter->acquire();
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

        if (($data['errorCode'] ?? null) === 'QUOTA_EXCEEDED') {
            $this->rateLimiter->penalize();

            throw new ThermorRateLimitedException($this->rateLimiter->backoffRemainingSeconds(), 'Overkiz polling quota exceeded.');
        }

        $this->rateLimiter->recordSuccess();

        return $data;
    }

    private function finishCurlRequest(\CurlHandle $ch, string|false $response, string $requestName): string
    {
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false) {
            throw new \RuntimeException(sprintf('%s request failed: %s', $requestName, $error));
        }
        if ($status === 429) {
            $this->rateLimiter->penalize();

            throw new ThermorRateLimitedException($this->rateLimiter->backoffRemainingSeconds(), sprintf('%s was rate limited.', $requestName));
        }
        if ($status < 200 || $status >= 300) {
            throw new OverkizApiException(sprintf('%s request failed with HTTP %d.', $requestName, $status), $status, $response);
        }

        return $response;
    }

    private function runCommands(string $cookieValue, string $deviceUrl, array $commands): void
    {
        foreach ($commands as $command) {
            $this->execCommand($cookieValue, $deviceUrl, $command['name'], $command['parameters']);
        }
    }

    private function execCommand(string $cookieValue, string $deviceUrl, string $commandName, array $parameters): array
    {
        $this->rateLimiter->acquire();
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
        if ($status === 429) {
            $this->rateLimiter->penalize();

            throw new ThermorRateLimitedException($this->rateLimiter->backoffRemainingSeconds(), 'Overkiz command was rate limited.');
        }
        if ($status < 200 || $status >= 300) {
            throw new OverkizApiException(sprintf('Overkiz command "%s" failed with HTTP %d.', $commandName, $status), $status, $response->getContent(false));
        }

        $responseData = $response->toArray(false);
        if (($responseData['errorCode'] ?? null) === 'QUOTA_EXCEEDED') {
            $this->rateLimiter->penalize();

            throw new ThermorRateLimitedException($this->rateLimiter->backoffRemainingSeconds(), 'Overkiz command quota exceeded.');
        }
        if (!isset($responseData['execId'])) {
            throw new \RuntimeException(sprintf('Overkiz did not acknowledge command "%s".', $commandName));
        }
        return $responseData;
    }
}