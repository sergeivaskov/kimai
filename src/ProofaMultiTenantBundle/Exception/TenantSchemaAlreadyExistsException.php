<?php

namespace App\ProofaMultiTenantBundle\Exception;

class TenantSchemaAlreadyExistsException extends TenantException
{
    protected string $errorType = 'schema_already_exists';

    public function __construct(string $workspaceId, ?\Throwable $previous = null)
    {
        $message = sprintf('Workspace schema already exists. Workspace ID: %s', $workspaceId);
        parent::__construct($message, 409, $previous, ['workspace_id' => $workspaceId]);
    }
}
