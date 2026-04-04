<?php

namespace App\ProofaMultiTenantBundle\MessageHandler;

use App\ProofaMultiTenantBundle\Message\MigrateTenantSchemaMessage;
use App\ProofaMultiTenantBundle\Service\TenantProvisioningService;
use App\ProofaMultiTenantBundle\Service\DefaultCustomerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class MigrateTenantSchemaHandler
{
    public function __construct(
        private TenantProvisioningService $provisioningService,
        private DefaultCustomerService $defaultCustomerService,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(MigrateTenantSchemaMessage $message): void
    {
        $schemaName = $message->getSchemaName();
        // Extract workspace ID from schema name (ws_xxx -> xxx)
        $workspaceId = substr($schemaName, 3);
        
        $this->logger->info('Starting async migration for tenant schema', [
            'schema' => $schemaName
        ]);

        try {
            $this->provisioningService->applyMigrationsToSchema($schemaName);
            
            // After successful migration, create the default customer
            $this->defaultCustomerService->createDefaultCustomer($schemaName, $workspaceId);
            
            $this->logger->info('Successfully completed async migration for tenant schema', [
                'schema' => $schemaName
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Async migration failed for tenant schema', [
                'schema' => $schemaName,
                'error' => $e->getMessage()
            ]);
            
            throw $e; // Re-throw to allow Messenger to retry
        }
    }
}
