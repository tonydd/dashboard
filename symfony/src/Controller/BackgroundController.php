<?php

namespace App\Controller;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/background')]
class BackgroundController extends AbstractController
{
    public function __invoke(
        KeyValueRepository $keyValueRepository,
        string $backgroundPath
    ): BinaryFileResponse {
        $today = new \DateTimeImmutable();
        $currentValue = $keyValueRepository->getValue(KeyValueKey::Background);
        if (!is_array($currentValue)
            || ($currentValue['currentDay'] ?? null) !== $today->format('Y-m-d')
            || !is_string($currentValue['background'] ?? null)
        ) {
            $currentValue = [
                'currentDay' => $today->format('Y-m-d'),
                'background' => $this->getRandomBackground($backgroundPath),
            ];
            $keyValueRepository->setValue(KeyValueKey::Background, $currentValue);
        }

        return $this->file(sprintf('%s/%s', $backgroundPath, $currentValue['background']), 'image.jpg', ResponseHeaderBag::DISPOSITION_INLINE);
    }

    private function getRandomBackground(string $backgroundPath): string
    {
        if (!is_dir($backgroundPath)) {
            throw $this->createNotFoundException('No background images are configured.');
        }

        $backgrounds = [];
        foreach (scandir($backgroundPath) as $file) {
            if (str_ends_with($file, '.jpg')) {
                $backgrounds[] = $file;
            }
        }

        if ($backgrounds === []) {
            throw $this->createNotFoundException('No background images are configured.');
        }

        return $backgrounds[array_rand($backgrounds)];
    }
}