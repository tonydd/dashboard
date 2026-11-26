<?php

namespace App\Controller;

use App\Service\CameraTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CameraController extends AbstractController
{
    public function __construct(
        private readonly CameraTokenService $cameraTokens,
    ) {
    }

    #[Route('/camera', name: 'dashboard_camera', methods: ['GET'])]
    public function camera(): Response
    {
        if (!$this->getUser()) {
            return $this->render('dashboard/login.html.twig');
        }

        return $this->render('dashboard/camera.html.twig');
    }

    #[Route('/api/camera/token', name: 'api_camera_token', methods: ['POST'])]
    #[IsGranted('ROLE_VIEWER')]
    #[IsCsrfTokenValid('api', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function token(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'A JSON object with a role value is required.'], Response::HTTP_BAD_REQUEST);
        }

        $role = is_array($payload) ? ($payload['role'] ?? null) : null;
        if ($role !== CameraTokenService::ROLE_PUBLISHER && $role !== CameraTokenService::ROLE_VIEWER) {
            return $this->json(['error' => "role must be 'publisher' or 'viewer'."], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->cameraTokens->issue($role));
    }
}
