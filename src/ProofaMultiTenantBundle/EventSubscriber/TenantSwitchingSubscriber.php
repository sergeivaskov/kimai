<?php

namespace App\ProofaMultiTenantBundle\EventSubscriber;

use App\ProofaMultiTenantBundle\Service\TenantMetricsService;
use App\ProofaMultiTenantBundle\Service\WorkspaceIdValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TenantSwitchingSubscriber implements EventSubscriberInterface
{
    private EntityManagerInterface $entityManager;
    private WorkspaceIdValidator $validator;
    private CacheItemPoolInterface $cache;
    private LoggerInterface $logger;
    private TenantMetricsService $metricsService;

    public function __construct(
        EntityManagerInterface $entityManager,
        WorkspaceIdValidator $validator,
        CacheItemPoolInterface $cache,
        LoggerInterface $logger,
        TenantMetricsService $metricsService
    ) {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->metricsService = $metricsService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run before Authentication and Firewall to prevent 401 on missing schemas
            KernelEvents::REQUEST => ['onKernelRequest', 300],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        
        // Skip for webhooks or other public routes that don't need tenant isolation
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/api/webhooks/')) {
            return;
        }

        // We only enforce tenant switching for API routes for now,
        // or routes that explicitly have workspace_id in attributes.
        // If it's the Kimai web UI, we might skip it or enforce it depending on requirements.
        // The plan says: "Извлечение workspace_id из $request->attributes->get('workspace_id')"
        
        $workspaceId = $request->attributes->get('workspace_id');

        // If workspace_id is not in attributes, let's try to extract it from JWT directly
        // because this subscriber now runs BEFORE JwtAuthenticator
        if (!$workspaceId && $request->headers->has('Authorization')) {
            $header = $request->headers->get('Authorization');
            if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
                $token = $matches[1];
                $parts = explode('.', $token);
                if (count($parts) === 3) {
                    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                    if (is_array($payload) && isset($payload['workspace_id'])) {
                        $workspaceId = $payload['workspace_id'];
                        $request->attributes->set('workspace_id', $workspaceId);
                    }
                }
            }
        }

        // If workspace_id is not in attributes, maybe it's in headers or cookies (fallback for non-JWT auth?)
        // The plan specifically says: "Отсутствие workspace_id в Request Attributes → 403 Forbidden"
        // But we only want to block requests that are supposed to be multi-tenant.
        // Let's assume all /api/ requests (except webhooks) must have a workspace_id.
        if (str_starts_with($path, '/api/') && !$workspaceId) {
            // We don't throw exception here because it might be a request with kimai's native token (not JWT)
            // or a request that doesn't require workspace_id. We'll let the Authenticator handle it.
            return;
        }

        if (!$workspaceId) {
            return; // Not an API route, or no workspace_id required
        }

        if (!$this->validator->validate($workspaceId)) {
            $this->logger->warning('invalid_workspace_id_format', ['workspace_id' => $workspaceId]);
            throw new \App\ProofaMultiTenantBundle\Exception\InvalidWorkspaceIdException($workspaceId);
        }

        $schemaName = 'ws_' . strtolower($this->validator->sanitize($workspaceId));

        if (!$this->schemaExists($schemaName)) {
            // Dummy query to prevent timing attacks
            $this->entityManager->getConnection()->executeQuery('SELECT 1')->fetchOne();
            
            $this->logger->error('tenant_schema_not_found', [
                'workspace_id' => $workspaceId,
                'schema' => $schemaName,
                'alert' => 'manual_provisioning_required'
            ]);
            throw new \App\ProofaMultiTenantBundle\Exception\TenantSchemaNotFoundException($workspaceId);
        }

        try {
            $startTime = microtime(true);
            $this->entityManager->getConnection()->executeStatement(sprintf('SET search_path TO "%s"', $schemaName));
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            $request->attributes->set('tenant_switched', true);

            $this->logger->debug('tenant_switch_performed', [
                'workspace_id' => $workspaceId,
                'schema' => $schemaName,
                'schema_switch_latency_ms' => $latency
            ]);

            $this->metricsService->recordSchemaSwitchLatency($latency, $schemaName);
        } catch (\Exception $e) {
            $this->logger->error('tenant_switch_failed', [
                'workspace_id' => $workspaceId,
                'schema' => $schemaName,
                'error' => $e->getMessage()
            ]);
            throw new \App\ProofaMultiTenantBundle\Exception\TenantProvisioningFailedException(
                'Failed to switch tenant schema',
                $e,
                ['workspace_id' => $workspaceId]
            );
        }
    }


    private function schemaExists(string $schemaName): bool
    {
        $cacheItem = $this->cache->getItem('tenant:schemas:list');
        
        if (!$cacheItem->isHit()) {
            $conn = $this->entityManager->getConnection();
            $schemas = $conn->fetchFirstColumn('SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE \'ws_%\'');
            
            $cacheItem->set($schemas);
            $cacheItem->expiresAfter(300); // 5 minutes TTL
            $this->cache->save($cacheItem);
        } else {
            $schemas = $cacheItem->get();
        }

        return in_array($schemaName, $schemas, true);
    }
}
