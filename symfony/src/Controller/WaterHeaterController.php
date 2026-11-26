<?php

namespace App\Controller;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use App\Repository\WaterHeaterStatRepository;
use App\Service\ThermorScheduleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class WaterHeaterController extends AbstractController
{
    private const HISTORY_LIMIT = 48;

    public function __construct(
        private readonly KeyValueRepository $keyValues,
        private readonly WaterHeaterStatRepository $waterHeaterStats,
        private readonly ThermorScheduleService $scheduleService,
    ) {
    }

    #[Route('/water-heater', name: 'water_heater', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->getUser()) {
            return $this->render('dashboard/login.html.twig');
        }

        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $current = $this->keyValues->getValue(KeyValueKey::Thermor);

        return $this->render('dashboard/water_heater.html.twig', [
            'current' => is_array($current) ? $current : null,
            'history' => $this->waterHeaterStats->findRecent(self::HISTORY_LIMIT),
            'schedule' => $this->scheduleService->getSchedule(),
            'rateLimit' => $this->rateLimitState(),
        ]);
    }

    /**
     * Raw state of the shared API budget, rendered best-effort: a missing
     * or malformed key must not break the page.
     */
    private function rateLimitState(): ?array
    {
        $state = $this->keyValues->getValue(KeyValueKey::ThermorApiRateLimit);

        return is_array($state) ? $state : null;
    }
}
