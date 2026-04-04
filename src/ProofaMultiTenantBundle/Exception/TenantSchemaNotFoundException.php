<?php

namespace App\ProofaMultiTenantBundle\Exception;

class TenantSchemaNotFoundException extends TenantException
{
    protected string $errorType = 'schema_not_found';

    public function __construct(string $workspaceId, ?\Throwable $previous = null)
    {
        $message = sprintf('Workspace schema not found. It may not be provisioned yet. Workspace ID: %s', $workspaceId);
        parent::__construct($message, 404, $previous, ['workspace_id' => $workspaceId]);
    }
}
