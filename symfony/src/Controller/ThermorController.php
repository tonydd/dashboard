<?php

namespace App\Controller;

use App\Enum\StatValueType;
use App\Repository\CurrentStatValueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/thermor')]
class ThermorController extends AbstractController
{
    public function __invoke(CurrentStatValueRepository $currentStatValueRepository)
    {
        $currentStatValue = $currentStatValueRepository->findOneBy(['type' => StatValueType::Thermor]);
        return $this->json($currentStatValue->getValue() ?? []);
    }
}