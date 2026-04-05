<?php

namespace App\Plugins\ProofaSyncBundle\Handler;

use App\Plugins\ProofaSyncBundle\Message\ShadowTeamDeletedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use App\Plugins\ProofaSyncBundle\Service\ShadowTeamManager;
use Psr\Log\LoggerInterface;

class ShadowTeamDeletedHandler
{
    public function __construct(
        private readonly IdempotencyGuard $idempotencyGuard,
        private readonly ShadowTeamManager $shadowTeamManager,
        private readonly LoggerInterface $logger
    ) {}

    public function handle(ShadowTeamDeletedMessage $message): void
    {
        $isNew = $this->idempotencyGuard->checkAndMark(
            $message->mutationId,
            $message->workspaceId,
            'shadow_team_deleted',
            $message->documentId,
            []
        );

        if (!$isNew) {
            return;
        }

        $this->shadowTeamManager->deleteShadowTeam($message->documentId);

        $this->logger->info('Shadow Team deleted', [
            'document_id' => $message->documentId,
        ]);
    }
}
