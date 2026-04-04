<?php

namespace App\ProofaMultiTenantBundle\Exception;

class TenantException extends \Exception
{
    protected array $context = [];
    protected string $errorType = 'tenant_error';

    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function toArray(): array
    {
        return [
            'error' => $this->getErrorType(),
            'message' => $this->getMessage(),
        ] + $this->context;
    }
}
