<?php

namespace App\Service;

use App\Entity\CurrentStatValue;
use App\Entity\WaterHeaterStat;
use App\Repository\CurrentStatValueRepository;
use App\Repository\WaterHeaterStatRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        private CurrentStatValueRepository $currentStatValueRepository,
        private SerializerInterface        $serializer,
        private WaterHeaterStatRepository  $waterHeaterStatRepository,
        private Filesystem                 $filesystem,
        private HttpClientInterface        $httpClient,
        private string                     $atlanticTokenUrl = '',
        private string                     $atlanticTokenAuthorization = '',
        private string                     $atlanticUsername = '',
        private string                     $atlanticPassword = '',
        private string                     $magellanTokenUrl = '',
        private string                     $overkizTokenUrl = '',
        private string                     $overkizAuthorization = '',
        private string                     $overkizEndpointUrl = '',
        private string                     $overkizEndpointCommandUrl = '',
    )
    {
    }


    public function command(): void
    {
        $atlanticToken = $this->atlanticToken();
        $magellanToken = $this->magellanToken($atlanticToken);
        $cookieValue = $this->endUserAPILogin($magellanToken);
        $this->execCommand($cookieValue);
    }

    public function update(): void
    {
        $atlanticToken = $this->atlanticToken();
        $magellanToken = $this->magellanToken($atlanticToken);
        $cookieValue = $this->endUserAPILogin($magellanToken);
        $setup = $this->setup($cookieValue);

        $this->filesystem->dumpFile('./var/files/thermorData.json', json_encode($setup, JSON_PRETTY_PRINT));

        $out = [];
        foreach (($setup['devices'] ?? []) as $device) {
            if ($device['controllableName'] === 'modbuslink:AtlanticDomesticHotWaterProductionMBLComponent') {
                $states = $device['states'];

                // Trouver l'état 'core:HeatingStatusState'
                $heatingStatusState = array_filter($states, function ($state) {
                    return $state['name'] === 'core:HeatingStatusState';
                });
                $heatingStatusState = reset($heatingStatusState);
                $out['heatingState'] = $heatingStatusState ? $heatingStatusState['value'] !== 'off' : null;

                // Trouver l'état 'modbuslink:MiddleWaterTemperatureState'
                $middleWaterTemperatureState = array_filter($states, function ($state) {
                    return $state['name'] === 'modbuslink:MiddleWaterTemperatureState';
                });
                $middleWaterTemperatureState = reset($middleWaterTemperatureState);
                $out['waterTemperature'] = $middleWaterTemperatureState ? $middleWaterTemperatureState['value'] : null;

                // Trouver l'état 'core:RemainingHotWaterState'
                $remainingHotWaterState = array_filter($states, function ($state) {
                    return $state['name'] === 'core:RemainingHotWaterState';
                });
                $remainingHotWaterState = reset($remainingHotWaterState);
                $out['V40Capacity'] = $remainingHotWaterState ? $remainingHotWaterState['value'] : null;

                // Trouver l'état 'modbuslink:DHWAbsenceModeState'
                $absenceModeState = array_filter($states, function ($state) {
                    return $state['name'] === 'modbuslink:DHWAbsenceModeState';
                });
                $absenceModeState = reset($absenceModeState);
                $out['absenceMode'] = $absenceModeState ? ($absenceModeState['value'] === 'off' ? 0 : 1) : null;

            } elseif ($device['controllableName'] === 'modbuslink:DHWCumulatedElectricalEnergyConsumptionMBLSystemDeviceSensor') {
                $states = $device['states'];

                // Trouver l'état 'core:ElectricEnergyConsumptionState'
                $electricEnergyConsumptionState = array_filter($states, function ($state) {
                    return $state['name'] === 'core:ElectricEnergyConsumptionState';
                });
                $electricEnergyConsumptionState = reset($electricEnergyConsumptionState);
                $out['electricalConsumption'] = $electricEnergyConsumptionState ? $electricEnergyConsumptionState['value'] : null;
            }
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

        $currentStatValue = $this->currentStatValueRepository->findOneBy(['type' => WaterHeaterStat::currentValueType()]);
        if ($currentStatValue) {
            $currentStatValue->setValue($this->serializer->normalize($waterHeaterStat));
        } else {
            $currentStatValue = new CurrentStatValue();
            $currentStatValue->setType(WaterHeaterStat::currentValueType());
            $currentStatValue->setValue($this->serializer->normalize($waterHeaterStat));
            $this->entityManager->persist($currentStatValue);
        }
        $this->entityManager->flush();
    }

    private function atlanticToken(): string
    {
        return $this->cache->get('atlanticToken', function (ItemInterface $item): ?string {
            $ch = curl_init();
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

            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $item->expiresAfter($data['expires_in'] - 6); // 6 secondes de marge
                return $data['access_token'];
            } else {
                $item->expiresAt(((new \DateTime())->modify('-1 day')));
                return null;
            }
        });
    }

    private function magellanToken(string $atlanticToken): string
    {
        return $this->cache->get('magellanToken', function (ItemInterface $item) use ($atlanticToken): ?string {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->magellanTokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $atlanticToken,
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            $jwt = str_replace('"', '', $response);
            $item->expiresAfter(300); // 125 secondes
            return $jwt;
        });
    }

    private function endUserAPILogin(string $magellanToken): string
    {
        return $this->cache->get('overkizToken', function (ItemInterface $item) use ($magellanToken): ?string {

            $ch = curl_init();
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
            $cookies = [];
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$cookies) {
                if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $headerLine, $cookie) == 1)
                    $cookies = $cookie;
                return strlen($headerLine); // Needed by curl
            });

            $response = curl_exec($ch);
            curl_close($ch);

            $cookieLine = $cookies[1];
            $cookieExplode = explode('=', $cookieLine);
            $cookieValue = $cookieExplode[1];
            $cookieExplode = explode('~', $cookieValue);
            $cookieValue = $cookieExplode[1];

            $item->expiresAfter(60 * 15); // 15 minutes
            return $cookieValue;
        });
    }

    private function setup(string $cookieValue): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->overkizEndpointUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Host: ha110-1.overkiz.com',
            'Connection: keep-alive',
            'Cache-Control: no-cache',
            'Cookie: JSESSIONID=' . $cookieValue,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    private function execCommand(string $cookieValue): array
    {
        $data = [];

        $response = $this->httpClient->request('POST', $this->overkizEndpointCommandUrl, [
            'headers' => [
                'Host' => 'ha110-1.overkiz.com',
                'Connection' => 'keep-alive',
                'Cache-Control' => 'no-cache',
                'Cookie' => 'JSESSIONID=' . $cookieValue,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'json' => $data,
        ]);

        $responseData = $response->toArray(false);
        //dd($responseData);

        return $responseData;
    }
}