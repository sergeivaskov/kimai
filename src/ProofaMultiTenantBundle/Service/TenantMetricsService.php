<?php

namespace App\ProofaMultiTenantBundle\Service;

use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;

class TenantMetricsService
{
    public function __construct(
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache
    ) {
    }

    public function recordSchemaSwitchLatency(float $latencyMs, string $schemaName): void
    {
        $this->logger->info('metric:schema_switch_latency_seconds', [
            'value' => round($latencyMs / 1000, 6),
            'schema' => $schemaName,
            'type' => 'histogram'
        ]);
    }

    public function recordStateResetLatency(float $latencyMs): void
    {
        $this->logger->info('metric:state_reset_latency_seconds', [
            'value' => round($latencyMs / 1000, 6),
            'type' => 'histogram'
        ]);
    }

    public function incrementMigrationFailures(string $schemaName): void
    {
        $this->logger->info('metric:schema_migration_failures_total', [
            'schema' => $schemaName,
            'type' => 'counter'
        ]);
    }

    public function updateActiveSchemasCount(): void
    {
        try {
            $cacheItem = $this->cache->getItem('tenant:metrics:active_schemas');
            if (!$cacheItem->isHit()) {
                return;
            }
            $count = count($cacheItem->get() ?? []);
            $this->logger->info('metric:tenant_schemas_total', [
                'value' => $count,
                'type' => 'gauge'
            ]);
        } catch (\Throwable $e) {
            // Metrics should never break the main flow
        }
    }
}
