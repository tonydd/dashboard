<?php

namespace App\Service;

use App\Entity\CurrentStatValue;
use App\Entity\FuelStat;
use App\Enum\FuelType;
use App\Repository\CurrentStatValueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;

class FuelService
{
    public function __construct(private EntityManagerInterface $entityManager, private CurrentStatValueRepository $currentStatValueRepository, private SerializerInterface $serializer)
    {
    }

    public function update(): void
    {
        $url = 'https://api.prix-carburants.2aaz.fr/station/67250002';


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Cache-Control: no-cache',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        $fuelData = $data['Fuels'];
        $fuels = [];
        foreach ($fuelData as $fuel) {
            if (in_array($fuel['short_name'], ['Gazole', 'SP95-E10', 'SP98'])) {
                $fuelStat = new FuelStat();
                $fuelStat->setPrice($fuel['Price']['value']);
                $fuelStat->setTs(new \DateTime());
                $fuelStat->setType($fuel['picto']);
                $this->entityManager->persist($fuelStat);
                $fuels[FuelType::from($fuel['picto'])->name] = $fuelStat;
            }
        }
        $this->entityManager->flush();

        $currentStatValue = $this->currentStatValueRepository->findOneBy(['type' => FuelStat::currentValueType()]);

        if ($currentStatValue) {
            $currentStatValue->setValue($this->serializer->normalize($fuels));
        } else {
            $currentStatValue = new CurrentStatValue();
            $currentStatValue->setType(FuelStat::currentValueType());
            $currentStatValue->setValue($this->serializer->normalize($fuels));
            $this->entityManager->persist($currentStatValue);
        }

        $this->entityManager->flush();
    }
}