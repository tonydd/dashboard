<?php

namespace App\Controller;

use App\Enum\KeyValueKey;
use App\Exception\ThermorRateLimitedException;
use App\Repository\KeyValueRepository;
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
    public function __invoke(KeyValueRepository $keyValueRepository): JsonResponse
    {
        return $this->json($keyValueRepository->getValue(KeyValueKey::Thermor) ?? []);
    }

    #[Route('/absence-mode', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function enableAbsenceMode(Request $request): JsonResponse
    {
        if ($response = $this->authorizeControl($request)) {
            return $response;
        }

        return $this->applyAbsenceMode(fn (): array => $this->thermorService->setAbsenceMode(true));
    }

    #[Route('/absence-mode', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function disableAbsenceMode(Request $request): JsonResponse
    {
        if ($response = $this->authorizeControl($request)) {
            return $response;
        }

        return $this->applyAbsenceMode(fn (): array => $this->thermorService->setAbsenceMode(false));
    }

    private function applyAbsenceMode(callable $action): JsonResponse
    {
        try {
            return $this->json($action(), JsonResponse::HTTP_ACCEPTED);
        } catch (ThermorRateLimitedException $e) {
            return $this->json(
                ['error' => 'The water-heater API budget is exhausted; try again later.'],
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => max(1, $e->retryAfterSeconds)],
            );
        }
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