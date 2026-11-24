<?php

namespace App\Controller;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/fuel')]
class FuelController extends AbstractController
{
    public function __invoke(KeyValueRepository $keyValueRepository): JsonResponse
    {
        return $this->json($keyValueRepository->getValue(KeyValueKey::Fuel) ?? []);
    }
}