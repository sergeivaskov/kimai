<?php

namespace App\ProofaMultiTenantBundle\EventSubscriber;

use App\Entity\Customer;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist, priority: 500, connection: 'default')]
class ProjectCustomerSubscriber
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof Project) {
            return;
        }

        if ($entity->getCustomer() !== null) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $defaultCustomer = $entityManager->getRepository(Customer::class)->findOneBy(['name' => 'Default']);

        if ($defaultCustomer !== null) {
            $entity->setCustomer($defaultCustomer);
        }
    }
}
