<?php

namespace App\Controller;

use App\Repository\SolarStatRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/solar')]
class SolarController extends AbstractController
{
    public function __invoke(SolarStatRepository $solarStatRepository, SerializerInterface $serializer): JsonResponse
    {
        $lastSolarStat = $solarStatRepository->findLast();

        return $this->json($serializer->normalize($lastSolarStat));
    }
}