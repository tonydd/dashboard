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
        'http://dendrijver.duckdns.org',
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
        if ($request->getMethod() === 'OPTIONS' && $request->headers->has('Origin')) {
            $response = new Response();
            $response->headers->set('Access-Control-Allow-Origin', $request->headers->get('Origin'));
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            $response->headers->set('Access-Control-Max-Age', '3600');
            $response->send();
            exit;

        }
    }

    public function onResponse(ResponseEvent $responseEvent): void
    {
        $request = $responseEvent->getRequest();
        if ($request->headers->has('Origin') && in_array($request->headers->get('Origin'), self::ALLOWED_ORIGINS)) {
            $response = $responseEvent->getResponse();
            $response->headers->set('Access-Control-Allow-Origin', $request->headers->get('Origin'));
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            $response->headers->set('Access-Control-Max-Age', '3600');
        }
    }
}