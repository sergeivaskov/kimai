<?php

namespace App\Plugins\ProofaSyncBundle\Message;

class ShadowTeamDeletedMessage
{
    public function __construct(
        public readonly string $eventType,
        public readonly string $mutationId,
        public readonly string $workspaceId,
        public readonly string $timestamp,
        public readonly string $correlationId,
        public readonly string $documentId,
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
            versionVector: $data['version_vector'] ?? null,
        );
    }
}
