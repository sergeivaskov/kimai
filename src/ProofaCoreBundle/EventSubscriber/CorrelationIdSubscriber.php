<?php

namespace App\ProofaCoreBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

class CorrelationIdSubscriber implements EventSubscriberInterface
{
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        
        $correlationId = $request->headers->get('X-Correlation-ID');
        if (!$correlationId) {
            $correlationId = Uuid::v4()->toRfc4122();
            $request->headers->set('X-Correlation-ID', $correlationId);
        }

        $request->attributes->set('_correlation_id', $correlationId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        
        $correlationId = $request->attributes->get('_correlation_id');
        if ($correlationId) {
            $response->headers->set('X-Correlation-ID', $correlationId);
            $response->headers->set('X-Request-ID', $correlationId);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 512],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }
}
