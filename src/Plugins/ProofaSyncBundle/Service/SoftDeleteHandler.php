<?php

namespace App\Plugins\ProofaSyncBundle\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class SoftDeleteHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    public function archiveEntity(string $entityType, string $affineId): void
    {
        match ($entityType) {
            'project' => $this->archiveByTable('kimai2_projects', $affineId),
            'activity' => $this->archiveByTable('kimai2_activities', $affineId),
            default => $this->logger->warning("Unsupported entity type for soft delete", ['type' => $entityType])
        };
    }

    private function archiveByTable(string $tableName, string $affineId): void
    {
        $affected = $this->connection->executeStatement(
            sprintf('UPDATE %s SET is_archived = true WHERE affine_id = :affine_id', $tableName),
            ['affine_id' => $affineId]
        );

        if ($affected === 0) {
            $this->logger->warning("Entity not found for archiving", [
                'table' => $tableName,
                'affine_id' => $affineId,
            ]);
            return;
        }

        $this->logger->info("Entity archived", [
            'table' => $tableName,
            'affine_id' => $affineId,
        ]);
    }
}
