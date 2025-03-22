<?php

namespace App\Service;

use App\Entity\CurrentStatValue;
use App\Entity\SolarStat;
use App\Repository\CurrentStatValueRepository;
use App\Repository\SolarStatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class SolarService
{
    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private CacheInterface $cache,
        private CurrentStatValueRepository $currentStatValueRepository,
        private SerializerInterface $serializer,
        private SolarStatRepository $solarStatRepository,
        private ?string $solarClientId = null,
        private ?string $solarClientSecret = null,
        private ?string $solarDeviceId = null,
        private ?string $solarApiUrl = null,
    ) {
    }

    public function update(): void
    {
        if (!$this->isSolarProductionPeriod()) {
            $this->logger->warning('Cannot update in non solar period');
            return;
        }

        $this->logger->info('Updating solar data');
        $accessToken = $this->getAccessToken();
        if ($accessToken) {
            $this->logger->info('Access token retrieved');
            $data = $this->getDeviceData($accessToken);

            if (isset($data['result'])) {

                $solarStat = $this->solarStatRepository->findLast();
                if ($solarStat && $solarStat->getProduction() === $data['result']['out_power']) {
                    $solarStat->setTs(new \DateTime());
                } else {
                    $solarStat = new SolarStat();
                    foreach ($data['result'] as $item) {
                        if ($item['code'] === 'out_power') {
                            $solarStat->setProduction($item['value']);
                        }
                    }
                    $solarStat->setTs(new \DateTime());
                    $this->entityManager->persist($solarStat);
                }
                $this->entityManager->flush();

                $currentStatValue = $this->currentStatValueRepository->findOneBy(['type' => SolarStat::currentValueType()]);
                if ($currentStatValue) {
                    $currentStatValue->setValue($this->serializer->normalize($solarStat));
                } else {
                    $currentStatValue = new CurrentStatValue();
                    $currentStatValue->setType(SolarStat::currentValueType());
                    $currentStatValue->setValue($this->serializer->normalize($solarStat));
                    $this->entityManager->persist($currentStatValue);
                }
                $this->entityManager->flush();

            } else {
                $this->logger->error('Cannot retrieve device data');
            }
        } else {
            $this->logger->error('Cannot retrieve access token');
        }
    }

    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function sha256EmptyBody(): string
    {
        return hash('sha256', '');
    }

    private function getAccessToken(): ?string
    {
        $cachedValue = $this->cache->get('tuyaToken', function (ItemInterface $item): ?string {
            $this->logger->info('Retrieving tuya access token from API');
            $now = round(microtime(true) * 1000);
            $nonce = $this->generateUUID(); // Génération d'un nonce

            // Construire la chaîne à signer
            $stringToSign = "GET\n" . $this->sha256EmptyBody() . "\n\n/v1.0/token?grant_type=1";
            $str = $this->solarClientId . $now . $nonce . $stringToSign;
            $sign = strtoupper(hash_hmac('sha256', $str, $this->solarClientSecret)); // Calculer la signature HMAC-SHA256

            // URL pour récupérer le token
            $url = $this->solarApiUrl . '/v1.0/token?grant_type=1';

            // Configuration de la requête cURL?
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'client_id: ' . $this->solarClientId,
                'sign: ' . $sign,
                't: ' . $now,
                'nonce: ' . $nonce,
                'sign_method: HMAC-SHA256'
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            if (isset($data['result']['access_token'])) {
                if (!$data['success']) {
                    return null;
                }

                $item->expiresAfter($data['result']['expire_time'] - 6);
                return $data['result']['access_token'];
            } else {
                $item->expiresAt(((new \DateTime())->modify('-1 day')));
                return null;
            }
        });

        dump($cachedValue);
        return $cachedValue;
    }

    private function getDeviceData($accessToken): array
    {
        $now = round(microtime(true) * 1000); // Timestamp en millisecondes
        $nonce = $this->generateUUID(); // Génération d'un nonce

        // Construction de la chaîne à signer pour les requêtes de données
        $stringToSign = "GET\n" . $this->sha256EmptyBody() . "\n\n/v1.0/devices/" . $this->solarDeviceId . "/status";
        $str = $this->solarClientId . $accessToken . $now . $nonce . $stringToSign;
        $sign = strtoupper(hash_hmac('sha256', $str, $this->solarClientSecret)); // Calculer la signature HMAC-SHA256

        $url = $this->solarApiUrl . '/v1.0/devices/' . $this->solarDeviceId . '/status';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'client_id: ' . $this->solarClientId,
            'sign: ' . $sign,
            't: ' . $now,
            'nonce: ' . $nonce,
            'sign_method: HMAC-SHA256',
            'access_token: ' . $accessToken,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['result'])) {
            return ($data);
        } else {
            dd("Erreur lors de la récupération des données de l'appareil : " . $data['msg'] . "\n");
        }
    }

    private const SUNRISE_SUNSET_PER_MONTH = [
        1 => ['sunrise' => ['hour' => 8, 'minute' => 30], 'sunset' => ['hour' => 16, 'minute' => 30]],
        2 => ['sunrise' => ['hour' => 8, 'minute' => 0], 'sunset' => ['hour' => 17, 'minute' => 0]],
        3 => ['sunrise' => ['hour' => 7, 'minute' => 30], 'sunset' => ['hour' => 18, 'minute' => 0]],
        4 => ['sunrise' => ['hour' => 7, 'minute' => 0], 'sunset' => ['hour' => 18, 'minute' => 30]],
        5 => ['sunrise' => ['hour' => 6, 'minute' => 30], 'sunset' => ['hour' => 19, 'minute' => 0]],
        6 => ['sunrise' => ['hour' => 6, 'minute' => 0], 'sunset' => ['hour' => 19, 'minute' => 30]],
        7 => ['sunrise' => ['hour' => 6, 'minute' => 0], 'sunset' => ['hour' => 19, 'minute' => 30]],
        8 => ['sunrise' => ['hour' => 6, 'minute' => 30], 'sunset' => ['hour' => 19, 'minute' => 0]],
        9 => ['sunrise' => ['hour' => 7, 'minute' => 0], 'sunset' => ['hour' => 18, 'minute' => 30]],
        10 => ['sunrise' => ['hour' => 7, 'minute' => 30], 'sunset' => ['hour' => 18, 'minute' => 0]],
        11 => ['sunrise' => ['hour' => 8, 'minute' => 0], 'sunset' => ['hour' => 17, 'minute' => 30]],
        12 => ['sunrise' => ['hour' => 8, 'minute' => 30], 'sunset' => ['hour' => 16, 'minute' => 30]],
    ];

    private function isSolarProductionPeriod(): bool
    {
        $date = new \DateTime();
        $hour = (int) $date->format('G');
        $minutes = (int) $date->format('i');
        $month = (int) $date->format('n');

        $sunrise = self::SUNRISE_SUNSET_PER_MONTH[$month]['sunrise'];
        $sunset = self::SUNRISE_SUNSET_PER_MONTH[$month]['sunset'];

        if ($hour < $sunrise['hour'] || $hour > $sunset['hour']) {
            return false;
        }
        if ($hour === $sunrise['hour'] && $minutes < $sunrise['minute']) {
            return false;
        }
        if ($hour === $sunset['hour'] && $minutes >= $sunset['minute']) {
            return false;
        }

        return true;
    }
}