<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestLifecycleSubscriber implements EventSubscriberInterface
{
    public function __construct(#[Autowire(service: 'monolog.logger.lifecycle')] private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['start', 1024], KernelEvents::EXCEPTION => 'failure', KernelEvents::TERMINATE => 'finish'];
    }

    public function start(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $event->getRequest()->attributes->set('_correlation_id', bin2hex(random_bytes(16)));
        $event->getRequest()->attributes->set('_lifecycle_start', microtime(true));
        $this->logger->info('http.request.started', ['correlation_id' => $event->getRequest()->attributes->get('_correlation_id')]);
    }

    public function failure(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->logger->error('http.request.failed', ['correlation_id' => $event->getRequest()->attributes->get('_correlation_id'), 'exception_class' => $event->getThrowable()::class]);
        }
    }

    public function finish(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $started = $request->attributes->get('_lifecycle_start');
        $duration = \is_float($started) ? (microtime(true) - $started) * 1000 : 0.0;
        $this->logger->info('http.request.completed', ['correlation_id' => $request->attributes->get('_correlation_id'), 'status_code' => $event->getResponse()->getStatusCode(), 'duration_ms' => $duration]);
        $request->attributes->remove('_correlation_id');
        $request->attributes->remove('_lifecycle_start');
    }
}
