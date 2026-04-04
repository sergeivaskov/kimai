<?php

namespace App\ProofaMultiTenantBundle\EventSubscriber;

use App\ProofaMultiTenantBundle\Exception\TenantException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TenantExceptionListener implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof TenantException) {
            return;
        }

        $statusCode = $exception->getCode() ?: 500;
        
        // Ensure valid HTTP status code
        if ($statusCode < 100 || $statusCode >= 600) {
            $statusCode = 500;
        }

        $responseArray = $exception->toArray();
        
        // Add correlation ID if available in request attributes
        $request = $event->getRequest();
        if ($request->attributes->has('_correlation_id')) {
            $responseArray['correlation_id'] = $request->attributes->get('_correlation_id');
        } elseif ($request->headers->has('X-Correlation-Id')) {
            $responseArray['correlation_id'] = $request->headers->get('X-Correlation-Id');
        }

        $this->logger->error('tenant_exception_caught', [
            'error' => $exception->getErrorType(),
            'message' => $exception->getMessage(),
            'context' => $exception->getContext(),
            'status_code' => $statusCode,
        ]);

        $response = new JsonResponse($responseArray, $statusCode);
        $event->setResponse($response);
    }
}
