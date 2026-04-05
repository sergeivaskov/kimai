<?php

namespace App\Plugins\ProofaSyncBundle\Handler;

use App\Plugins\ProofaSyncBundle\Message\ProjectCreatedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class ProjectCreatedHandler
{
    public function __construct(
        private readonly IdempotencyGuard $idempotencyGuard,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    public function handle(ProjectCreatedMessage $message): void
    {
        $isNew = $this->idempotencyGuard->checkAndMark(
            $message->mutationId,
            $message->workspaceId,
            'project',
            $message->affineId,
            $message->versionVector ?? []
        );

        if (!$isNew) {
            return;
        }

        $customerId = $message->payload['customer_id'] ?? null;
        if (!$customerId) {
            $customerId = $this->connection->fetchOne('SELECT id FROM kimai2_customers ORDER BY id ASC LIMIT 1');
        }

        if (!$customerId) {
            $this->logger->error('No customer found in Kimai to assign project', [
                'mutation_id' => $message->mutationId,
                'affine_id' => $message->affineId,
            ]);
            return;
        }

        $existingProjectId = $this->connection->fetchOne(
            'SELECT id FROM kimai2_projects WHERE affine_id = :affine_id',
            ['affine_id' => $message->affineId]
        );

        if ($existingProjectId) {
            $this->connection->executeStatement(
                'UPDATE kimai2_projects SET name = :name WHERE affine_id = :affine_id',
                [
                    'name' => $message->payload['name'],
                    'affine_id' => $message->affineId,
                ]
            );

            $this->logger->info('Project updated via sync', [
                'mutation_id' => $message->mutationId,
                'affine_id' => $message->affineId,
                'correlation_id' => $message->correlationId,
            ]);
            return;
        }

        $this->connection->insert('kimai2_projects', [
            'name' => $message->payload['name'],
            'customer_id' => $customerId,
            'visible' => true,
            'is_archived' => false,
            'billable' => true,
            'global_activities' => true,
            'budget' => 0,
            'time_budget' => 0,
            'affine_id' => $message->affineId,
            'comment' => $message->payload['comment'] ?? null,
        ], [
            'visible' => 'boolean',
            'is_archived' => 'boolean',
            'billable' => 'boolean',
            'global_activities' => 'boolean',
        ]);

        $this->logger->info('Project created via sync', [
            'mutation_id' => $message->mutationId,
            'affine_id' => $message->affineId,
            'name' => $message->payload['name'],
            'correlation_id' => $message->correlationId,
        ]);
    }
}
