<?php

namespace App\Plugins\ProofaAuthBundle\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class JwtUserProvider implements UserProviderInterface
{
    private EntityManagerInterface $entityManager;
    private RequestStack $requestStack;

    public function __construct(EntityManagerInterface $entityManager, RequestStack $requestStack)
    {
        $this->entityManager = $entityManager;
        $this->requestStack = $requestStack;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // Identifier is passed from JwtAuthenticator: affine_id|json_encoded_payload
        $parts = explode('|', $identifier, 2);
        $affineId = $parts[0];
        $payload = isset($parts[1]) ? json_decode($parts[1], true) : [];

        $userRepository = $this->entityManager->getRepository(User::class);
        
        // We need to find the user by affine_id
        // NOTE: In Stage 4 we will add affine_id to the User entity.
        // For now, let's assume it exists or fallback to email.
        $email = $payload['email'] ?? null;
        
        $user = null;
        if (property_exists(User::class, 'affine_id')) {
            try {
                $user = $userRepository->findOneBy(['affine_id' => $affineId]);
            } catch (\Exception $e) {
                // Ignore if schema not updated yet
            }
        }
        
        if (!$user && $email) {
            $user = $userRepository->findOneBy(['email' => $email]);
        }

        if (!$user) {
            // JIT Provisioning will handle this in Stage 4, but for now we throw if not found
            // Or we could create it here. The epic says JIT is Stage 4.
            throw new UserNotFoundException(sprintf('User with affine_id "%s" not found.', $affineId));
        }

        // Sync Roles
        $workspaceId = $this->requestStack->getCurrentRequest()?->headers->get('X-Workspace-Id');
        
        if ($workspaceId && isset($payload['workspaces'][$workspaceId])) {
            $jwtRoles = $payload['workspaces'][$workspaceId];
            
            // Map AFFiNE roles to Kimai roles
            $mappedRoles = [];
            foreach ($jwtRoles as $role) {
                if ($role === 'ROLE_WORKSPACE_OWNER') {
                    $mappedRoles[] = 'ROLE_SUPER_ADMIN';
                } elseif ($role === 'ROLE_WORKSPACE_ADMIN') {
                    $mappedRoles[] = 'ROLE_ADMIN';
                } else {
                    $mappedRoles[] = 'ROLE_USER';
                }
            }
            
            // Ensure ROLE_USER is always present
            if (!in_array('ROLE_USER', $mappedRoles)) {
                $mappedRoles[] = 'ROLE_USER';
            }

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
