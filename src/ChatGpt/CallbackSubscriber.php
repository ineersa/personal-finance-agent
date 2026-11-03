<?php

declare(strict_types=1);

namespace App\ChatGpt;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(private CallbackPage $page)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Return before RouterListener can log the authorization query string.
        return [KernelEvents::REQUEST => ['onRequest', 4096]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && Connection::CALLBACK_PATH === rawurldecode($event->getRequest()->getPathInfo())) {
            try {
                $response = $this->page->respond($event->getRequest());
            } catch (\Throwable) {
                $response = new Response('The local callback is unavailable. Return to Finance and try again.', 503, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                    'Referrer-Policy' => 'no-referrer',
                    'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
                ]);
            }
            $event->setResponse($response);
        }
    }
}
