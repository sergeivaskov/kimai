<?php

namespace App\Plugins\ProofaSyncBundle\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class IdempotencyGuard
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Проверяет, был ли mutation_id уже обработан.
     * Если нет — вставляет запись в proofa_processed_mutations.
     * 
     * @return bool true если событие новое (нужно обработать), false если дубликат
     */
    public function checkAndMark(
        string $mutationId,
        string $workspaceId,
        string $entityType,
        string $entityId,
        array $versionVector
    ): bool {
        try {
            $this->connection->insert('proofa_processed_mutations', [
                'mutation_id' => $mutationId,
                'workspace_id' => $workspaceId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'version_vector' => json_encode($versionVector),
                'processed_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            
            return true; // Новое событие, обработать
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            $this->logger->info('Duplicate mutation_id detected, skipping', [
                'mutation_id' => $mutationId,
                'workspace_id' => $workspaceId,
            ]);
            
            return false; // Дубликат, пропустить
        }
    }
    
    /**
     * Получает сохранённый version_vector для сущности.
     */
    public function getVersionVector(string $entityType, string $entityId): ?array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT version_vector FROM proofa_processed_mutations 
             WHERE entity_type = ? AND entity_id = ? 
             ORDER BY processed_at DESC LIMIT 1',
            [$entityType, $entityId]
        );
        
        return $result ? json_decode($result['version_vector'], true) : null;
    }
}
