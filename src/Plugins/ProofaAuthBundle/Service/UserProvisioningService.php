<?php

namespace App\Plugins\ProofaAuthBundle\Service;

use App\Entity\User;
use App\Plugins\ProofaAuthBundle\Entity\ProofaUserMapping;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

class UserProvisioningService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function provisionUser(array $jwtPayload): ?User
    {
        $affineId = $jwtPayload['sub'] ?? null;
        $email = $jwtPayload['email'] ?? null;

        if (!$affineId || !$email) {
            $this->logger->warning('Cannot provision user: missing sub or email in JWT', ['payload' => $jwtPayload]);
            return null;
        }

        // Check if mapping already exists (Idempotency check 1)
        $mappingRepo = $this->entityManager->getRepository(ProofaUserMapping::class);
        $existingMapping = $mappingRepo->findOneBy(['affineId' => $affineId]);
        if ($existingMapping) {
            return $existingMapping->getKimaiUser();
        }

        $this->entityManager->beginTransaction();
        try {
            // Check if Kimai user already exists by email
            $userRepo = $this->entityManager->getRepository(User::class);
            $user = $userRepo->findOneBy(['email' => $email]);

            if (!$user) {
                $user = new User();
                $user->setEmail($email);
                $user->setUserIdentifier($email); // Kimai requires username/identifier
                $user->setUsername($email);
                $user->setEnabled(true);
                // Set a random password since they authenticate via AFFiNE
                $user->setPassword(bin2hex(random_bytes(16)));
                
                // Map roles (Task 4.5)
                $jwtRole = $jwtPayload['kimai_role'] ?? 'ROLE_USER';
                $mappedRoles = ['ROLE_USER'];
                if (in_array($jwtRole, ['ROLE_WORKSPACE_OWNER', 'ROLE_SUPER_ADMIN'])) {
                    $mappedRoles[] = 'ROLE_SUPER_ADMIN';
                } elseif (in_array($jwtRole, ['ROLE_WORKSPACE_ADMIN', 'ROLE_ADMIN'])) {
                    $mappedRoles[] = 'ROLE_ADMIN';
                } elseif ($jwtRole === 'ROLE_TEAMLEAD') {
                    $mappedRoles[] = 'ROLE_TEAMLEAD';
                }
                $user->setRoles(array_unique($mappedRoles));

                $this->entityManager->persist($user);
                $this->entityManager->flush(); // flush to get ID
            }

            $mapping = new ProofaUserMapping();
            $mapping->setKimaiUser($user);
            $mapping->setAffineId($affineId);

            $this->entityManager->persist($mapping);
            $this->entityManager->flush();
            
            $this->entityManager->commit();
            $this->logger->info('User provisioned successfully', [
                'event' => 'jit_provisioning_success',
                'user_id' => $affineId,
                'kimai_user_id' => $user->getId()
            ]);
            
            return $user;
        } catch (UniqueConstraintViolationException $e) {
            $this->entityManager->rollback();
            $this->logger->info('User already provisioned (concurrent request)', [
                'event' => 'jit_provisioning_concurrent',
                'user_id' => $affineId
            ]);
            // Fetch the user that was just created by another thread
            $this->entityManager->clear(); // Clear EM to avoid detached entities
            $mapping = $this->entityManager->getRepository(ProofaUserMapping::class)->findOneBy(['affineId' => $affineId]);
            return $mapping ? $mapping->getKimaiUser() : null;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to provision user', [
                'event' => 'jit_provisioning_error',
                'exception' => $e->getMessage(),
                'user_id' => $affineId
            ]);
            throw $e;
        }
    }
}
