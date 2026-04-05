<?php

namespace App\Plugins\ProofaSyncBundle\Message;

class EntityDeletedMessage
{
    public function __construct(
        public readonly string $eventType,
        public readonly string $mutationId,
        public readonly string $workspaceId,
        public readonly string $timestamp,
        public readonly string $correlationId,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $affineId,
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
            entityType: $data['entity_type'],
            entityId: $data['entity_id'],
            affineId: $data['affine_id'],
            versionVector: $data['version_vector'] ?? null,
        );
    }
}
