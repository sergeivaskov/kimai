<?php

namespace App\ProofaMultiTenantBundle\Exception;

class InvalidWorkspaceIdException extends TenantException
{
    protected string $errorType = 'invalid_workspace_id';

    public function __construct(string $workspaceId, ?\Throwable $previous = null)
    {
        $message = sprintf('Invalid workspace ID format or reserved name. Workspace ID: %s', $workspaceId);
        parent::__construct($message, 403, $previous, ['workspace_id' => $workspaceId]);
    }
}
