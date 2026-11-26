<?php

namespace App\Controller;

use App\Enum\KeyValueKey;
use App\Exception\OverkizApiException;
use App\Exception\ThermorRateLimitedException;
use App\Repository\KeyValueRepository;
use App\Service\ThermorScheduleService;
use App\Service\ThermorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/api/thermor')]
#[IsCsrfTokenValid('api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
class ThermorController extends AbstractController
{
    public function __construct(
        private readonly ThermorService $thermorService,
        private readonly ThermorScheduleService $scheduleService,
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

    /**
     * Screen-driven actions: authenticated admins with a CSRF token, no
     * Bearer token required (unlike the external absence-mode endpoints).
     */
    #[Route('/actions/absence-mode', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionAbsenceMode(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);

        return $this->applyAction(
            fn (): array => $this->thermorService->setAbsenceMode($this->boolField($payload, 'enabled'))
        );
    }

    #[Route('/actions/target-temperature', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionTargetTemperature(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);

        return $this->applyAction(fn (): array => $this->thermorService->setTargetTemperature(
            $this->floatField($payload, 'temperature'),
        ));
    }

    #[Route('/actions/dhw-mode', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionDhwMode(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);

        return $this->applyAction(fn (): array => $this->thermorService->setDhwMode(
            $this->stringField($payload, 'mode'),
        ));
    }

    #[Route('/actions/boost', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionBoost(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);

        return $this->applyAction(
            fn (): array => $this->thermorService->setBoostMode($this->boolField($payload, 'enabled'))
        );
    }

    #[Route('/actions/anti-legionellosis', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionAntiLegionellosis(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);

        return $this->applyAction(
            fn (): array => $this->thermorService->setAntiLegionellosis($this->boolField($payload, 'enabled'))
        );
    }

    #[Route('/actions/smart-grid', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionSmartGrid(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);

        return $this->applyAction(fn (): array => $this->thermorService->setSmartGridOption(
            $this->stringField($payload, 'option'),
        ));
    }

    #[Route('/actions/operating-range', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionOperatingRange(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);

        return $this->applyAction(fn (): array => $this->thermorService->setOperatingRange(
            $this->stringField($payload, 'range'),
        ));
    }

    #[Route('/actions/expected-showers', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionExpectedShowers(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);

        return $this->applyAction(fn (): array => $this->thermorService->setExpectedNumberOfShower(
            $this->intField($payload, 'count'),
        ));
    }

    #[Route('/actions/programming-slots', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionProgrammingSlots(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        $slots = $payload['slots'] ?? null;
        if (!is_array($slots)) {
            throw new BadRequestHttpException('The "slots" field must be an object.');
        }

        return $this->applyAction(fn (): array => $this->thermorService->setProgrammingSlots($slots));
    }

    #[Route('/actions/sync-clock', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionSyncClock(): JsonResponse
    {
        return $this->applyAction(fn (): array => $this->thermorService->synchronizeDateTime());
    }

    /**
     * Local configuration only: never calls the Overkiz cloud, so no rate
     * limit applies. The off-peak reconciliation picks the change up on its
     * next tick.
     */
    #[Route('/actions/off-peak-schedule', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function actionOffPeakSchedule(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        $days = $payload['days'] ?? null;
        if (!is_array($days)) {
            throw new BadRequestHttpException('The "days" field must be an object.');
        }

        try {
            $schedule = $this->scheduleService->setSchedule(['days' => $days]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['schedule' => $schedule]);
    }

    private function applyAction(callable $action): JsonResponse
    {
        try {
            return $this->json($action(), JsonResponse::HTTP_ACCEPTED);
        } catch (ThermorRateLimitedException $e) {
            return $this->json(
                ['error' => 'The water-heater API budget is exhausted; try again later.'],
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => max(1, $e->retryAfterSeconds)],
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (BadRequestHttpException $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (OverkizApiException | \RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_GATEWAY);
        }
    }

    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('The request body must be valid JSON.');
        }

        if (!is_array($payload)) {
            throw new BadRequestHttpException('The request body must be a JSON object.');
        }

        return $payload;
    }

    private function boolField(array $payload, string $field): bool
    {
        if (!is_bool($payload[$field] ?? null)) {
            throw new BadRequestHttpException(sprintf('The "%s" field must be a boolean.', $field));
        }

        return $payload[$field];
    }

    private function stringField(array $payload, string $field): string
    {
        if (!is_string($payload[$field] ?? null) || $payload[$field] === '') {
            throw new BadRequestHttpException(sprintf('The "%s" field must be a non-empty string.', $field));
        }

        return $payload[$field];
    }

    private function intField(array $payload, string $field): int
    {
        if (!is_int($payload[$field] ?? null)) {
            throw new BadRequestHttpException(sprintf('The "%s" field must be an integer.', $field));
        }

        return $payload[$field];
    }

    private function floatField(array $payload, string $field): float
    {
        if (!is_int($payload[$field] ?? null) && !is_float($payload[$field] ?? null)) {
            throw new BadRequestHttpException(sprintf('The "%s" field must be a number.', $field));
        }

        return (float) $payload[$field];
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