<?php

namespace App\Plugins\ProofaAuthBundle\Repository;

use App\Plugins\ProofaAuthBundle\Entity\ProofaUserMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProofaUserMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProofaUserMapping::class);
    }
}
