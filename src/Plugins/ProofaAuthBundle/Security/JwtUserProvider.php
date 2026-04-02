<?php

namespace App\Plugins\ProofaAuthBundle\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class JwtUserProvider implements UserProviderInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // Identifier is passed from JwtAuthenticator: affine_id|json_encoded_payload
        $parts = explode('|', $identifier, 2);
        $affineId = $parts[0];
        $payload = isset($parts[1]) ? json_decode($parts[1], true) : [];

        $userRepository = $this->entityManager->getRepository(User::class);
        $email = $payload['email'] ?? null;
        
        $user = null;

        // Stage 4 will introduce ProofaUserMapping. For now, we fallback to email match.
        // If ProofaUserMapping exists (Stage 4), we should use it here.
        if (class_exists('App\Plugins\ProofaAuthBundle\Entity\ProofaUserMapping')) {
            $mappingRepo = $this->entityManager->getRepository('App\Plugins\ProofaAuthBundle\Entity\ProofaUserMapping');
            $mapping = $mappingRepo->findOneBy(['affineId' => $affineId]);
            if ($mapping) {
                $user = $mapping->getKimaiUser();
            }
        }
        
        if (!$user && $email) {
            $user = $userRepository->findOneBy(['email' => $email]);
        }

        if (!$user) {
            throw new UserNotFoundException(sprintf('User with affine_id "%s" not found.', $affineId));
        }

        // Sync Roles
        if (isset($payload['kimai_role'])) {
            $jwtRole = $payload['kimai_role'];
            
            // Map AFFiNE roles to Kimai roles
            $mappedRoles = ['ROLE_USER']; // Ensure ROLE_USER is always present
            
            if ($jwtRole === 'ROLE_WORKSPACE_OWNER' || $jwtRole === 'ROLE_SUPER_ADMIN') {
                $mappedRoles[] = 'ROLE_SUPER_ADMIN';
            } elseif ($jwtRole === 'ROLE_WORKSPACE_ADMIN' || $jwtRole === 'ROLE_ADMIN') {
                $mappedRoles[] = 'ROLE_ADMIN';
            } elseif ($jwtRole === 'ROLE_TEAMLEAD') {
                $mappedRoles[] = 'ROLE_TEAMLEAD';
            }

            $mappedRoles = array_unique($mappedRoles);
            $currentRoles = $user->getRoles();
            
            // If roles differ, update and flush
            sort($mappedRoles);
            sort($currentRoles);
            
            if ($mappedRoles !== $currentRoles) {
                $user->setRoles($mappedRoles);
                $this->entityManager->flush();
            }
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        // JWT is stateless, no need to refresh from session
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
