<?php

namespace App\Controller;

use App\Enum\StatValueType;
use App\Repository\CurrentStatValueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/fuel')]
class FuelController extends AbstractController
{
    public function __invoke(CurrentStatValueRepository $currentStatValueRepository): JsonResponse
    {
        $currentStatValue = $currentStatValueRepository->findOneBy(['type' => StatValueType::Fuel]);
        return $this->json($currentStatValue->getValue() ?? []);
    }
}