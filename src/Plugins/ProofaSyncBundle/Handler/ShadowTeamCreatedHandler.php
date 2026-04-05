<?php

namespace App\Plugins\ProofaSyncBundle\Handler;

use App\Plugins\ProofaSyncBundle\Message\ShadowTeamCreatedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use App\Plugins\ProofaSyncBundle\Service\ShadowTeamManager;
use Psr\Log\LoggerInterface;

class ShadowTeamCreatedHandler
{
    public function __construct(
        private readonly IdempotencyGuard $idempotencyGuard,
        private readonly ShadowTeamManager $shadowTeamManager,
        private readonly LoggerInterface $logger
    ) {}

    public function handle(ShadowTeamCreatedMessage $message): void
    {
        $isNew = $this->idempotencyGuard->checkAndMark(
            $message->mutationId,
            $message->workspaceId,
            'shadow_team',
            $message->documentId,
            []
        );

        if (!$isNew) {
            return;
        }

        $this->shadowTeamManager->createOrUpdateShadowTeam(
            $message->documentId,
            $message->userIds,
            $message->teamleadIds
        );

        $this->logger->info('Shadow Team created', [
            'document_id' => $message->documentId,
            'users_count' => count($message->userIds),
        ]);
    }
}
