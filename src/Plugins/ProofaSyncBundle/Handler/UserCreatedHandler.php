<?php

namespace App\Plugins\ProofaSyncBundle\Handler;

use App\Plugins\ProofaSyncBundle\Message\UserCreatedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class UserCreatedHandler
{
    public function __construct(
        private readonly IdempotencyGuard $idempotencyGuard,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    public function handle(UserCreatedMessage $message): void
    {
        $isNew = $this->idempotencyGuard->checkAndMark(
            $message->mutationId,
            $message->workspaceId,
            'user',
            $message->affineId,
            $message->versionVector ?? []
        );

        if (!$isNew) {
            return;
        }

        // Запись affine_id через DBAL (не через ORM entity setter)
        $affected = $this->connection->executeStatement(
            'UPDATE kimai2_users SET affine_id = :affine_id WHERE email = :email AND affine_id IS NULL',
            [
                'affine_id' => $message->affineId, 
                'email' => $message->payload['email']
            ]
        );

        if ($affected === 0) {
            $this->logger->warning('UserCreated: no user found with matching email or affine_id already set', [
                'mutation_id' => $message->mutationId,
                'affine_id' => $message->affineId,
                'email' => $message->payload['email'],
                'correlation_id' => $message->correlationId,
            ]);
        }

        $this->logger->info('Processing UserCreated event', [
            'mutation_id' => $message->mutationId,
            'affine_id' => $message->affineId,
            'affected_rows' => $affected,
            'correlation_id' => $message->correlationId
        ]);
    }
}
