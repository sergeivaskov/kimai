<?php

namespace App\Tests\Plugins\ProofaAuthBundle;

use App\Entity\User;
use App\Plugins\ProofaAuthBundle\Entity\ProofaUserMapping;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;

class TestDataFactory
{
    private string $jwtSecret;
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->jwtSecret = $_ENV['AFFINE_JWT_SECRET'] ?? 'test-secret-key-minimum-32-bytes-long-for-hs256-algorithm-needs-at-least-256-bits';
    }

    public function createTestUser(
        ?string $affineId = null,
        ?string $email = null,
        array $roles = ['ROLE_USER']
    ): User {
        $affineId = $affineId ?? 'affine-' . uniqid();
        $email = $email ?? 'test-' . uniqid() . '@example.com';

        $user = new User();
        $user->setUsername($email);
        $user->setEmail($email);
        $user->setEnabled(true);
        $user->setRoles($roles);
        $user->setPassword('dummy-password-hash-' . bin2hex(random_bytes(16)));
        
        $this->em->persist($user);
        
        $mapping = new ProofaUserMapping();
        $mapping->setAffineId($affineId);
        $mapping->setKimaiUser($user);
        $this->em->persist($mapping);
        
        $this->em->flush();

        return $user;
    }

    public function generateValidJwt(
        string $userId,
        string $email,
        ?string $workspaceId = null,
        array $roles = ['ROLE_USER'],
        ?int $expiresIn = 900
    ): string {
        $workspaceId = $workspaceId ?? 'workspace-' . uniqid();
        
        $payload = [
            'sub' => $userId,
            'email' => $email,
            'kimai_role' => $roles[0] ?? 'ROLE_USER',
            'workspaces' => [$workspaceId => $roles],
            'workspace_id' => $workspaceId,
            'iss' => 'proofa-identity',
            'aud' => 'proofa-services',
            'iat' => time(),
            'exp' => time() + $expiresIn,
        ];

        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    public function generateExpiredJwt(
        string $userId,
        string $email
    ): string {
        $payload = [
            'sub' => $userId,
            'email' => $email,
            'kimai_role' => 'ROLE_USER',
            'workspaces' => [],
            'iss' => 'proofa-identity',
            'aud' => 'proofa-services',
            'iat' => time() - 3600,
            'exp' => time() - 1800,
        ];

        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    public function generateInvalidSignatureJwt(
        string $userId,
        string $email
    ): string {
        $payload = [
            'sub' => $userId,
            'email' => $email,
            'kimai_role' => 'ROLE_USER',
            'workspaces' => [],
            'iss' => 'proofa-identity',
            'aud' => 'proofa-services',
            'iat' => time(),
            'exp' => time() + 900,
        ];

        return JWT::encode($payload, 'wrong-secret-key-minimum-32-bytes-long-for-hs256-algorithm-needs-at-least-256-bits', 'HS256');
    }

    public function cleanupTestData(): void
    {
        $this->em->createQuery('DELETE FROM App\Plugins\ProofaAuthBundle\Entity\ProofaUserMapping')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User WHERE email LIKE :pattern')
            ->setParameter('pattern', '%@example.com')
            ->execute();
    }
}
