<?php

namespace App\Controller;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use App\Service\DashboardFeedService;
use App\Service\DashboardSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly KeyValueRepository $keyValues,
        private readonly string $fuelStationName,
        private readonly string $backgroundToken,
        private readonly DashboardSettingsService $settings,
        private readonly DashboardFeedService $feeds,
    ) {
    }

    #[Route('/', name: 'dashboard_root')]
    public function root(): Response
    {
        return $this->redirectToRoute('dashboard_tablet');
    }

    #[Route('/tablet', name: 'dashboard_tablet', methods: ['GET'])]
    public function tablet(): Response
    {
        if (!$this->getUser()) {
            return $this->render('dashboard/login.html.twig');
        }

        return $this->render('dashboard/tablet.html.twig', $this->dashboardData());
    }

    #[Route('/slideshow', name: 'dashboard_slideshow', methods: ['GET'])]
    public function slideshow(): Response
    {
        if (!$this->getUser()) {
            return $this->render('dashboard/login.html.twig');
        }

        return $this->render('dashboard/slideshow.html.twig', [
            'backgroundToken' => $this->backgroundToken,
        ]);
    }

    #[Route('/api/weather', name: 'api_weather', methods: ['GET'])]
    public function weather(): JsonResponse
    {
        if (!$this->feeds->isWeatherConfigured()) {
            return $this->json(['error' => 'La météo n’est pas configurée.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $weather = $this->feeds->weather();

        return $weather === null
            ? $this->json(['error' => 'La météo est momentanément indisponible.'], Response::HTTP_SERVICE_UNAVAILABLE)
            : $this->json($weather);
    }

    #[Route('/api/news', name: 'api_news', methods: ['GET'])]
    public function news(): JsonResponse
    {
        $news = $this->feeds->news();

        return $news === null
            ? $this->json(['error' => 'Les actualités sont momentanément indisponibles.'], Response::HTTP_SERVICE_UNAVAILABLE)
            : $this->json(['items' => $news]);
    }

    #[Route('/api/dashboard/settings/slideshow-return', name: 'api_dashboard_slideshow_return', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('api', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function setSlideshowReturnDuration(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'A JSON object with an integer seconds value is required.'], Response::HTTP_BAD_REQUEST);
        }

        $seconds = is_array($payload) ? ($payload['seconds'] ?? null) : null;
        $validated = DashboardSettingsService::validateSlideshowReturnSeconds($seconds);
        if ($validated === null) {
            return $this->json([
                'error' => sprintf(
                    'seconds must be an integer between %d and %d.',
                    DashboardSettingsService::MIN_SLIDESHOW_RETURN_SECONDS,
                    DashboardSettingsService::MAX_SLIDESHOW_RETURN_SECONDS,
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->settings->setSlideshowReturnSeconds($validated);

        return $this->json(['seconds' => $validated]);
    }

    private function dashboardData(): array
    {
        return [
            'solar' => $this->keyValues->getValue(KeyValueKey::Solar) ?? [],
            'thermor' => $this->keyValues->getValue(KeyValueKey::Thermor) ?? [],
            'waterQuality' => $this->keyValues->getValue(KeyValueKey::WaterQuality) ?? [],
            'fuel' => $this->keyValues->getValue(KeyValueKey::Fuel) ?? [],
            'fuelStationName' => $this->fuelStationName,
            'slideshowReturnSeconds' => $this->settings->slideshowReturnSeconds(),
        ];
    }

}
