<?php

namespace App\Plugins\ProofaSyncBundle\Message;

class ShadowTeamCreatedMessage
{
    public function __construct(
        public readonly string $eventType,
        public readonly string $mutationId,
        public readonly string $workspaceId,
        public readonly string $timestamp,
        public readonly string $correlationId,
        public readonly string $documentId,
        public readonly array $userIds,
        public readonly array $teamleadIds,
        public readonly ?array $versionVector = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            eventType: $data['event_type'],
            mutationId: $data['mutation_id'],
            workspaceId: $data['workspace_id'],
            timestamp: $data['timestamp'],
            correlationId: $data['correlation_id'],
            documentId: $data['document_id'],
            userIds: $data['user_ids'],
            teamleadIds: $data['teamlead_ids'],
            versionVector: $data['version_vector'] ?? null,
        );
    }
}
