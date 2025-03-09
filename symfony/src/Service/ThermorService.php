<?php

namespace App\Service;

use App\Entity\WaterHeaterStat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class ThermorService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CacheInterface         $cache,
        private string                 $atlanticTokenUrl = '',
        private string                 $atlanticTokenAuthorization = '',
        private string                 $atlanticUsername = '',
        private string                 $atlanticPassword = '',
        private string                 $magellanTokenUrl = '',
        private string                 $overkizTokenUrl = '',
        private string                 $overkizAuthorization = '',
        private string                 $overkizEndpointUrl = '',
    )
    {
    }

    public function update(): void
    {
        $atlanticToken = $this->atlanticToken();
        $magellanToken = $this->magellanToken($atlanticToken);
        $cookieValue = $this->endUserAPILogin($magellanToken);
        $setup = $this->setup($cookieValue);

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

        $waterHeaterStat = new WaterHeaterStat();
        $waterHeaterStat->setHeatingState($out['heatingState']);
        $waterHeaterStat->setWaterTemperature($out['waterTemperature']);
        $waterHeaterStat->setAvailable40Degrees($out['V40Capacity']);
        $waterHeaterStat->setWh($out['electricalConsumption']);
        $waterHeaterStat->setTs(new \DateTime());
        $this->entityManager->persist($waterHeaterStat);
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
}