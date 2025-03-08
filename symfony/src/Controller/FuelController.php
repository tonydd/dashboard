<?php

namespace App\Controller;

use App\Enum\FuelType;
use App\Repository\FuelStatRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/fuel')]
class FuelController extends AbstractController
{
    public function __invoke(FuelStatRepository $fuelStatRepository, SerializerInterface $serializer)
    {
        $lastGazoled = $fuelStatRepository->findLastByType(FuelType::Gazole);
        $lastSP98 = $fuelStatRepository->findLastByType(FuelType::SP98);
        $lastSP95E10 = $fuelStatRepository->findLastByType(FuelType::SP95E10);

        return $this->json([
            'gazoled' => $serializer->normalize($lastGazoled),
            'sp98' => $serializer->normalize($lastSP98),
            'sp95e10' => $serializer->normalize($lastSP95E10),
        ]);
    }
}