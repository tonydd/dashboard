<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class AuthenticationController extends AbstractController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(['error' => 'Authentication failed.'], JsonResponse::HTTP_UNAUTHORIZED);
    }

    #[Route('/api/session', name: 'api_session', methods: ['GET'])]
    public function session(CsrfTokenManagerInterface $csrfTokenManager): JsonResponse
    {
        return $this->json([
            'authenticated' => $this->getUser() !== null,
            'csrfToken' => $csrfTokenManager->getToken('api')->getValue(),
        ]);
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    #[IsCsrfTokenValid('api', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function logout(Request $request): JsonResponse
    {
        $request->getSession()->invalidate();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
