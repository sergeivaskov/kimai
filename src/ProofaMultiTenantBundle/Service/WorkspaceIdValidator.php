<?php

namespace App\ProofaMultiTenantBundle\Service;

class WorkspaceIdValidator
{
    /**
     * Validates if the workspace ID is in correct format and safe to use.
     */
    public function validate(string $workspaceId): bool
    {
        // Whitelist regex: 3 to 64 characters, alphanumeric, dash, underscore
        if (!preg_match('/^[a-zA-Z0-9_-]{3,64}$/', $workspaceId)) {
            return false;
        }

        // Blacklist reserved schema names
        $reservedNames = ['public', 'information_schema', 'pg_catalog', 'pg_toast'];
        if (in_array(strtolower($workspaceId), $reservedNames, true)) {
            return false;
        }

        return true;
    }

    /**
     * Sanitizes the workspace ID.
     * Since we already validate it strictly, this is just an extra precaution.
     */
    public function sanitize(string $workspaceId): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $workspaceId);
    }
}
