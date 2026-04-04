<?php

namespace App\ProofaMultiTenantBundle\Exception;

class TenantProvisioningFailedException extends TenantException
{
    protected string $errorType = 'provisioning_failed';

    public function __construct(string $message, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, 500, $previous, $context);
    }
}
