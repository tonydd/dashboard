<?php

namespace App\Controller;

use App\Enum\StatValueType;
use App\Repository\CurrentStatValueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/waterQuality')]
class WaterQualityController extends AbstractController
{
    public function __invoke(CurrentStatValueRepository $currentStatValueRepository): JsonResponse
    {
        $currentStatValue = $currentStatValueRepository->findOneBy(['type' => StatValueType::WaterQuality]);
        return $this->json($currentStatValue->getValue() ?? []);
    }
}