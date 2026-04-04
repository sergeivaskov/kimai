<?php

namespace App\ProofaMultiTenantBundle\Service;

use App\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DefaultCustomerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Creates a default customer in the currently active schema.
     * Assumes that the search_path is already set to the target schema.
     */
    public function createDefaultCustomer(string $schemaName, string $workspaceId): Customer
    {
        // Check if a default customer already exists to prevent duplicates
        $existingCustomer = $this->entityManager->getRepository(Customer::class)->findOneBy(['name' => 'Default']);
        
        if ($existingCustomer !== null) {
            $this->logger->info('default_customer_already_exists', [
                'schema' => $schemaName,
                'workspace_id' => $workspaceId,
            ]);
            return $existingCustomer;
        }

        $customer = new Customer('Default');
        $customer->setComment(sprintf('Auto-generated for Workspace %s', $workspaceId));
        $customer->setTimezone('UTC');
        $customer->setCountry('XX');
        $customer->setCurrency('USD');
        $customer->setVisible(true);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->logger->info('default_customer_created', [
            'schema' => $schemaName,
            'workspace_id' => $workspaceId,
            'customer_id' => $customer->getId(),
        ]);

        return $customer;
    }
}
