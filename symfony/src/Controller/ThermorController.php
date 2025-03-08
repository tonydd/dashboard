<?php

namespace App\Controller;

use App\Entity\WaterHeaterStat;
use App\Repository\WaterHeaterStatRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/thermor')]
class ThermorController extends AbstractController
{
    public function __construct(private WaterHeaterStatRepository $waterHeaterStatRepository, private SerializerInterface $serializer)
    {
    }

    public function __invoke()
    {
        $lastWaterHeaterStat = $this->waterHeaterStatRepository->findLast();

        return $this->json($this->serializer->normalize($lastWaterHeaterStat));
    }
}