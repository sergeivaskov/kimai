<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

class CorrelationIdSubscriber implements EventSubscriberInterface
{
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        
        // Получаем из заголовка или генерируем новый
        $correlationId = $request->headers->get('X-Correlation-ID');
        if (!$correlationId) {
            $correlationId = Uuid::v4()->toRfc4122();
            $request->headers->set('X-Correlation-ID', $correlationId);
        }

        // В Monolog мы можем использовать процессор для добавления этого ID во все логи.
        // Для этого сохраняем его в атрибутах запроса
        $request->attributes->set('_correlation_id', $correlationId);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
        ];
    }
}
