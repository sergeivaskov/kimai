<?php

namespace App\ProofaMultiTenantBundle\Service;

use App\ProofaMultiTenantBundle\Exception\InvalidWorkspaceIdException;
use App\ProofaMultiTenantBundle\Exception\TenantProvisioningFailedException;
use App\ProofaMultiTenantBundle\Exception\TenantSchemaAlreadyExistsException;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use App\ProofaMultiTenantBundle\Message\MigrateTenantSchemaMessage;

class TenantProvisioningService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceIdValidator $validator,
        private DefaultCustomerService $defaultCustomerService,
        private LoggerInterface $logger,
        private DependencyFactory $dependencyFactory,
        private CacheItemPoolInterface $cache,
        private MessageBusInterface $messageBus
    ) {
    }

    public function schemaExists(string $workspaceId): bool
    {
        if (!$this->validator->validate($workspaceId)) {
            throw new InvalidWorkspaceIdException($workspaceId);
        }

        $schemaName = 'ws_' . strtolower($this->validator->sanitize($workspaceId));
        $connection = $this->entityManager->getConnection();

        $sql = 'SELECT schema_name FROM information_schema.schemata WHERE schema_name = :schema';
        $result = $connection->executeQuery($sql, ['schema' => $schemaName])->fetchOne();

        return $result !== false;
    }

    public function createTenantSchema(string $workspaceId): void
    {
        if (!$this->validator->validate($workspaceId)) {
            throw new InvalidWorkspaceIdException($workspaceId);
        }

        if ($this->schemaExists($workspaceId)) {
            throw new TenantSchemaAlreadyExistsException($workspaceId);
        }

        $sanitizedId = $this->validator->sanitize($workspaceId);
        $schemaName = 'ws_' . strtolower($sanitizedId);
        $quotedSchema = sprintf('"%s"', $schemaName);

        $connection = $this->entityManager->getConnection();

        $lockKey = 'tenant:provisioning:' . $workspaceId;
        $lockItem = $this->cache->getItem($lockKey);
        if ($lockItem->isHit()) {
            throw new TenantProvisioningFailedException(
                sprintf('Provisioning already in progress for workspace %s', $workspaceId),
                null,
                ['workspace_id' => $workspaceId]
            );
        }
        $lockItem->set(true);
        $lockItem->expiresAfter(60);
        $this->cache->save($lockItem);

        try {
            // 1. Create Schema
            $connection->executeStatement(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $quotedSchema));
            $this->logger->info('tenant_schema_created', ['workspace_id' => $workspaceId, 'schema' => $schemaName]);

            // 1.5. Record schema in public.proofa_tenant_schemas
            $connection->executeStatement('
                INSERT INTO public.proofa_tenant_schemas (workspace_id, schema_name, status, created_at)
                VALUES (:workspace_id, :schema_name, :status, :created_at)
                ON CONFLICT (workspace_id) DO NOTHING
            ', [
                'workspace_id' => $workspaceId,
                'schema_name' => $schemaName,
                'status' => 'active',
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);

            // 2. Apply Migrations (Async)
            $this->messageBus->dispatch(new MigrateTenantSchemaMessage($schemaName));
            $this->logger->info('tenant_migration_dispatched', ['workspace_id' => $workspaceId, 'schema' => $schemaName]);

            // 3. Create Default Customer
            // We move this to the MessageHandler to avoid race conditions with migrations
            // $this->defaultCustomerService->createDefaultCustomer($schemaName, $workspaceId);

            // Reset search path to public just in case
            $connection->executeStatement('SET search_path TO public');

            // Invalidate schema cache
            $this->cache->deleteItem('tenant:schemas:list');

        } catch (\Throwable $e) {
            $this->logger->error('tenant_provisioning_failed_rollback', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Rollback: Drop schema
            try {
                $connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $quotedSchema));
                $connection->executeStatement('DELETE FROM public.proofa_tenant_schemas WHERE workspace_id = :workspace_id', [
                    'workspace_id' => $workspaceId
                ]);
            } catch (\Throwable $cleanupError) {
                $this->logger->critical('tenant_cleanup_failed', [
                    'workspace_id' => $workspaceId,
                    'error' => $cleanupError->getMessage(),
                ]);
            }

            // Reset search path
            $connection->executeStatement('SET search_path TO public');

            throw new TenantProvisioningFailedException(
                sprintf('Failed to provision schema for workspace %s', $workspaceId),
                $e,
                ['workspace_id' => $workspaceId]
            );
        } finally {
            $this->cache->deleteItem($lockKey);
        }
    }

    public function handleWorkspaceDeletion(string $workspaceId): void
    {
        if (!$this->validator->validate($workspaceId)) {
            throw new InvalidWorkspaceIdException($workspaceId);
        }

        $schemaName = 'ws_' . strtolower($this->validator->sanitize($workspaceId));
        $quotedSchema = sprintf('"%s"', $schemaName);
        $connection = $this->entityManager->getConnection();

        // Check if schema exists
        if (!$this->schemaExists($workspaceId)) {
            $this->logger->warning('tenant_deletion_skipped_not_found', ['workspace_id' => $workspaceId, 'schema' => $schemaName]);
            return;
        }

        // Check for active Time Entries in the last 7 days
        $connection->executeStatement(sprintf('SET search_path TO %s', $quotedSchema));
        
        try {
            $recentEntriesCount = (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM kimai2_timesheet WHERE start_time >= :date",
                ['date' => (new \DateTime('-7 days'))->format('Y-m-d H:i:s')]
            );

            if ($recentEntriesCount > 0) {
                $this->logger->warning('tenant_deletion_rejected_active_entries', [
                    'workspace_id' => $workspaceId,
                    'schema' => $schemaName,
                    'recent_entries' => $recentEntriesCount
                ]);
                throw new \RuntimeException('Cannot delete workspace with active time entries in the last 7 days.');
            }

            // Mark all Time Entries as exported (archived)
            $connection->executeStatement("UPDATE kimai2_timesheet SET exported = true");
            
        } finally {
            $connection->executeStatement('SET search_path TO public');
        }

        // Update status to archived
        $connection->executeStatement("
            UPDATE public.proofa_tenant_schemas 
            SET status = 'archived', deleted_at = :deleted_at 
            WHERE workspace_id = :workspace_id
        ", [
            'deleted_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'workspace_id' => $workspaceId
        ]);

        $this->logger->info('tenant_schema_archived', [
            'workspace_id' => $workspaceId,
            'schema' => $schemaName
        ]);
        
        // Invalidate schema cache
        $this->cache->deleteItem('tenant:schemas:list');
    }

    public function applyMigrationsToSchema(string $schemaName): void
    {
        $connection = $this->entityManager->getConnection();
        $quotedSchema = sprintf('"%s"', $schemaName);

        // Set search path to the new schema
        $connection->executeStatement(sprintf('SET search_path TO %s', $quotedSchema));

        // Filter out public schema assets to prevent SequenceAlreadyExists errors during introspection
        $configuration = $connection->getConfiguration();
        $originalFilter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(function ($assetName) use ($originalFilter) {
            $name = $assetName instanceof \Doctrine\DBAL\Schema\AbstractAsset ? $assetName->getName() : (string) $assetName;
            
            if ($originalFilter && !$originalFilter($assetName)) {
                return false;
            }
            
            // DBAL strips the schema prefix for assets in the default schema (our tenant schema).
            // Assets from other schemas (like 'public') will retain their prefix (e.g., 'public.kimai2_users').
            // We only want to introspect the tenant schema, so we reject any asset with a schema prefix.
            return !str_contains($name, '.');
        });

        try {
            $migrator = $this->dependencyFactory->getMigrator();
            $planCalculator = $this->dependencyFactory->getMigrationPlanCalculator();

            // Initialize metadata storage for the new schema.
            // Since DependencyFactory is a singleton, MetadataStorage caches the 'initialized' state.
            // We manually ensure the table exists in the current schema to bypass this cache.
            $connection->executeStatement('
                CREATE TABLE IF NOT EXISTS migration_versions (
                    version VARCHAR(191) NOT NULL,
                    executed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                    execution_time INT DEFAULT NULL,
                    PRIMARY KEY(version)
                )
            ');
            $this->dependencyFactory->getMetadataStorage()->ensureInitialized();

            // Get all available migrations up to latest
            $versionAliasResolver = $this->dependencyFactory->getVersionAliasResolver();
            $latestVersion = $versionAliasResolver->resolveVersionAlias('latest');
            $plan = $planCalculator->getPlanUntilVersion($latestVersion);

            // Apply them
            $migratorConfiguration = $this->dependencyFactory->getConsoleInputMigratorConfigurationFactory()->getMigratorConfiguration(
                new \Symfony\Component\Console\Input\ArrayInput([])
            );
            $migrator->migrate($plan, $migratorConfiguration);

            $this->logger->info('tenant_migrations_applied', ['schema' => $schemaName]);
            
            // 9.1 Schema Warmup
            $this->warmupSchema($connection, $schemaName);
            
        } catch (\Throwable $e) {
            $this->logger->error('tenant_migrations_failed', [
                'schema' => $schemaName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            // Restore original filter
            $configuration->setSchemaAssetsFilter($originalFilter);
        }
    }

    private function warmupSchema(Connection $connection, string $schemaName): void
    {
        $quotedSchema = sprintf('"%s"', $schemaName);
        
        $queries = [
            sprintf('SELECT 1 FROM %s.kimai2_users LIMIT 1', $quotedSchema),
            sprintf('SELECT 1 FROM %s.kimai2_projects LIMIT 1', $quotedSchema),
            sprintf('SELECT 1 FROM %s.kimai2_timesheet LIMIT 1', $quotedSchema),
        ];

        foreach ($queries as $query) {
            try {
                $connection->executeQuery($query);
            } catch (\Throwable $e) {
                $this->logger->warning('tenant_schema_warmup_failed', [
                    'schema' => $schemaName,
                    'query' => $query,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('tenant_schema_warmed_up', ['schema' => $schemaName]);
    }
}
