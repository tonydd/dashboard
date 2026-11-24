<?php

namespace App\Controller;

use App\Enum\StatValueType;
use App\Repository\CurrentStatValueRepository;
use App\Service\ThermorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/api/thermor')]
#[IsCsrfTokenValid('api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
class ThermorController extends AbstractController
{
    public function __construct(
        private readonly ThermorService $thermorService,
        private readonly string $thermorApiToken,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function __invoke(CurrentStatValueRepository $currentStatValueRepository): JsonResponse
    {
        $currentStatValue = $currentStatValueRepository->findOneBy(['type' => StatValueType::Thermor]);
        return $this->json($currentStatValue?->getValue() ?? []);
    }

    #[Route('/absence-mode', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function enableAbsenceMode(Request $request): JsonResponse
    {
        if ($response = $this->authorizeControl($request)) {
            return $response;
        }

        return $this->json($this->thermorService->setAbsenceMode(true), JsonResponse::HTTP_ACCEPTED);
    }

    #[Route('/absence-mode', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function disableAbsenceMode(Request $request): JsonResponse
    {
        if ($response = $this->authorizeControl($request)) {
            return $response;
        }

        return $this->json($this->thermorService->setAbsenceMode(false), JsonResponse::HTTP_ACCEPTED);
    }

    private function authorizeControl(Request $request): ?JsonResponse
    {
        if ($this->thermorApiToken === '') {
            return $this->json(['error' => 'Water-heater control is not configured.'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        $authorization = $request->headers->get('Authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)
            || !hash_equals($this->thermorApiToken, $matches[1])
        ) {
            return $this->json(
                ['error' => 'A valid Bearer token is required.'],
                JsonResponse::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

        return null;
    }
}