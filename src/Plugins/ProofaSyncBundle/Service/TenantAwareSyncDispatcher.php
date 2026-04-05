<?php

namespace App\Plugins\ProofaSyncBundle\Service;

use Doctrine\DBAL\Connection;

class TenantAwareSyncDispatcher
{
    public function __construct(
        private readonly SyncEventDispatcher $innerDispatcher,
        private readonly Connection $connection,
    ) {}

    public function dispatch(array $eventData): void
    {
        $workspaceId = $eventData['workspace_id'] ?? null;
        if (!$workspaceId) {
            throw new \InvalidArgumentException('Missing workspace_id in sync event');
        }

        $schemaName = 'ws_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $workspaceId);

        $schemaExists = $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.schemata WHERE schema_name = :schema",
            ['schema' => $schemaName]
        );

        if (!$schemaExists) {
            throw new \RuntimeException(sprintf('Tenant schema "%s" does not exist', $schemaName));
        }

        try {
            $this->connection->beginTransaction();
            $this->connection->executeStatement(sprintf('SET LOCAL search_path TO "%s"', $schemaName));
            $this->innerDispatcher->dispatch($eventData);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
