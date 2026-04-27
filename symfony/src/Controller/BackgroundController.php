<?php

namespace App\Controller;

use App\Entity\CurrentStatValue;
use App\Enum\StatValueType;
use App\Repository\CurrentStatValueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/api/background')]
class BackgroundController extends AbstractController
{
    public function __invoke(
        CurrentStatValueRepository $currentStatValueRepository,
        NormalizerInterface $normalizer,
        EntityManagerInterface $entityManager,
        string $backgroundPath
    ): BinaryFileResponse {
        $currentStatValue = $currentStatValueRepository->findOneBy(['type' => StatValueType::Background]);

        if (!$currentStatValue) {
            $currentStatValue = new CurrentStatValue();
            $currentStatValue->setType(StatValueType::Background);
            $currentStatValue->setValue($normalizer->normalize(['currentDay' => (new \DateTimeImmutable())->format('Y-m-d'), 'background' => $this->getRandomBackground($backgroundPath)]));
            $entityManager->persist($currentStatValue);
            $entityManager->flush();
        }

        $today = new \DateTimeImmutable();
        $currentValue = $currentStatValue->getValue();
        if (!$currentValue || $currentValue['currentDay'] !== $today->format('Y-m-d')) {
            $currentStatValue->setValue($normalizer->normalize(['currentDay' => $today->format('Y-m-d'), 'background' => $this->getRandomBackground($backgroundPath)]));
            $entityManager->flush();
        }

        return $this->file(sprintf('%s/%s', $backgroundPath, $currentStatValue->getValue()['background']), 'image.jpg', ResponseHeaderBag::DISPOSITION_INLINE);
    }

    private function getRandomBackground(string $backgroundPath): string
    {
        $backgrounds = [];
        foreach (scandir($backgroundPath) as $file) {
            if (str_ends_with($file, '.jpg')) {
                $backgrounds[] = $file;
            }
        }

        return $backgrounds[array_rand($backgrounds)];
    }
}