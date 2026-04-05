<?php

namespace App\Plugins\ProofaSyncBundle\Handler;

use App\Plugins\ProofaSyncBundle\Message\EntityDeletedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use App\Plugins\ProofaSyncBundle\Service\SoftDeleteHandler;
use Psr\Log\LoggerInterface;

class EntityDeletedHandler
{
    public function __construct(
        private readonly IdempotencyGuard $idempotencyGuard,
        private readonly SoftDeleteHandler $softDeleteHandler,
        private readonly LoggerInterface $logger
    ) {}

    public function handle(EntityDeletedMessage $message): void
    {
        $isNew = $this->idempotencyGuard->checkAndMark(
            $message->mutationId,
            $message->workspaceId,
            $message->entityType . '_deleted',
            $message->affineId,
            []
        );

        if (!$isNew) {
            return;
        }

        $this->softDeleteHandler->archiveEntity($message->entityType, $message->affineId);

        $this->logger->info('Entity soft deleted', [
            'entity_type' => $message->entityType,
            'affine_id' => $message->affineId,
        ]);
    }
}
