<?php

namespace App\ProofaMultiTenantBundle\Message;

class MigrateTenantSchemaMessage
{
    public function __construct(
        private string $schemaName
    ) {
    }

    public function getSchemaName(): string
    {
        return $this->schemaName;
    }
}
