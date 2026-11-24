<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class KernelSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ORIGINS = [
        'http://localhost:5174',
        'https://localhost:5174',
        'http://127.0.0.1:5174',
        'https://127.0.0.1:5174',
        'https://dendrijver.duckdns.org',
    ];

    public static function getSubscribedEvents(): array
    {
        // return the subscribed events, their methods and priorities
        return [
            KernelEvents::REQUEST => [
                ['onRequest', 4096],
            ],
            KernelEvents::RESPONSE => [
                ['onResponse', 10],
            ]
        ];
    }

    public function onRequest(RequestEvent $requestEvent): void
    {
        $request = $requestEvent->getRequest();
        $origin = $request->headers->get('Origin');
        if ($request->getMethod() === 'OPTIONS' && $origin !== null && in_array($origin, self::ALLOWED_ORIGINS, true)) {
            $response = new Response('', Response::HTTP_NO_CONTENT);
            $this->addCorsHeaders($response, $origin);
            $requestEvent->setResponse($response);
        }
    }

    public function onResponse(ResponseEvent $responseEvent): void
    {
        $request = $responseEvent->getRequest();
        $origin = $request->headers->get('Origin');
        if ($origin !== null && in_array($origin, self::ALLOWED_ORIGINS, true)) {
            $this->addCorsHeaders($responseEvent->getResponse(), $origin);
        }
    }

    private function addCorsHeaders(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-Token');
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Vary', 'Origin');
    }
}