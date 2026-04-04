<?php

namespace App\ProofaMultiTenantBundle\EventSubscriber;

use App\ProofaMultiTenantBundle\Service\TenantMetricsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TenantStateResetSubscriber implements EventSubscriberInterface
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private TenantMetricsService $metricsService;

    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger, TenantMetricsService $metricsService)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->metricsService = $metricsService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // kernel.response (приоритет: -256) — основной сброс, выполняется до отправки ответа.
            KernelEvents::RESPONSE => ['onKernelResponse', -256],
            // kernel.terminate — дублирующий сброс как страховка
            KernelEvents::TERMINATE => ['onKernelTerminate', -256],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->resetState($event->getRequest());
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->resetState($event->getRequest());
    }

    private function resetState($request): void
    {
        if (!$request->attributes->get('tenant_switched')) {
            return;
        }

        $conn = $this->entityManager->getConnection();
        if ($conn->isConnected()) {
            try {
                $startTime = microtime(true);
                $conn->executeStatement('SET search_path TO "public"');
                $latency = round((microtime(true) - $startTime) * 1000, 2);

                // Сброс Doctrine Identity Map
                $this->entityManager->clear();

                // Снимаем флаг, чтобы не сбрасывать дважды (в response и terminate)
                $request->attributes->remove('tenant_switched');

                $this->logger->debug('tenant_state_reset_performed', [
                    'state_reset_latency_ms' => $latency
                ]);

                $this->metricsService->recordStateResetLatency($latency);
            } catch (\Exception $e) {
                $this->logger->critical('tenant_state_reset_failed', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
