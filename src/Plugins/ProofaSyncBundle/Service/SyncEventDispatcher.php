<?php

namespace App\Plugins\ProofaSyncBundle\Service;

use App\Plugins\ProofaSyncBundle\Handler\UserCreatedHandler;
use App\Plugins\ProofaSyncBundle\Handler\ProjectCreatedHandler;
use App\Plugins\ProofaSyncBundle\Handler\EntityDeletedHandler;
use App\Plugins\ProofaSyncBundle\Handler\ShadowTeamCreatedHandler;
use App\Plugins\ProofaSyncBundle\Handler\ShadowTeamDeletedHandler;
use App\Plugins\ProofaSyncBundle\Message\UserCreatedMessage;
use App\Plugins\ProofaSyncBundle\Message\ProjectCreatedMessage;
use App\Plugins\ProofaSyncBundle\Message\EntityDeletedMessage;
use App\Plugins\ProofaSyncBundle\Message\ShadowTeamCreatedMessage;
use App\Plugins\ProofaSyncBundle\Message\ShadowTeamDeletedMessage;
use Psr\Log\LoggerInterface;

class SyncEventDispatcher
{
    public function __construct(
        private readonly UserCreatedHandler $userCreatedHandler,
        private readonly ProjectCreatedHandler $projectCreatedHandler,
        private readonly EntityDeletedHandler $entityDeletedHandler,
        private readonly ShadowTeamCreatedHandler $shadowTeamCreatedHandler,
        private readonly ShadowTeamDeletedHandler $shadowTeamDeletedHandler,
        private readonly LoggerInterface $logger
    ) {}

    public function dispatch(array $eventData): void
    {
        $eventType = $eventData['event_type'] ?? null;

        match ($eventType) {
            'UserCreated' => $this->userCreatedHandler->handle(
                UserCreatedMessage::fromArray($eventData)
            ),
            'ProjectCreated' => $this->projectCreatedHandler->handle(
                ProjectCreatedMessage::fromArray($eventData)
            ),
            'EntityDeleted' => $this->entityDeletedHandler->handle(
                EntityDeletedMessage::fromArray($eventData)
            ),
            'ShadowTeamCreated' => $this->shadowTeamCreatedHandler->handle(
                ShadowTeamCreatedMessage::fromArray($eventData)
            ),
            'ShadowTeamDeleted' => $this->shadowTeamDeletedHandler->handle(
                ShadowTeamDeletedMessage::fromArray($eventData)
            ),
            default => $this->logger->warning('Unknown event type', ['type' => $eventType]),
        };
    }
}
