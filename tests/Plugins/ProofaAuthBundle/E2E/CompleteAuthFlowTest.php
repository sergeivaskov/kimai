<?php

namespace App\Tests\Plugins\ProofaAuthBundle\E2E;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Tests\Plugins\ProofaAuthBundle\TestDataFactory;
use App\Entity\User;

/**
 * End-to-End tests for complete authentication flow across AFFiNE and Kimai
 */
class CompleteAuthFlowTest extends WebTestCase
{
    private string $jwtSecret;
    private TestDataFactory $factory;
    private string $affineBaseUrl;
    private string $kimaiBaseUrl;

    protected function setUp(): void
    {
        $this->jwtSecret = $_ENV['AFFINE_JWT_SECRET'] ?? 'test-secret-key-minimum-32-bytes-long-for-hs256-algorithm-needs-at-least-256-bits';
        $this->affineBaseUrl = $_ENV['AFFINE_INTERNAL_URL'] ?? 'http://affine-server:3010';
        $this->kimaiBaseUrl = 'http://localhost';
    }

    private function getFactory(): TestDataFactory
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        return new TestDataFactory($em);
    }

    public function testCompleteAuthenticationFlow(): void
    {
        $client = static::createClient();
        $factory = $this->getFactory();
        
        // Step 1: Simulate user authentication in AFFiNE and JWT generation
        $affineId = 'e2e-test-' . uniqid();
        $email = 'e2e-' . uniqid() . '@example.com';
        $workspaceId = 'workspace-e2e-' . uniqid();
        
        $jwt = $factory->generateValidJwt($affineId, $email, $workspaceId, ['ROLE_USER']);
        
        $this->assertNotEmpty($jwt, 'JWT should be generated');
        
        // Step 2: Use JWT to access Kimai protected resource
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        // Should redirect to wizard (authenticated user without data)
        $this->assertResponseRedirects('/en/wizard/intro', 302, 'User should be authenticated and redirected');
        
        // Step 3: Verify user was provisioned in Kimai
        $container = static::getContainer();
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);
        
        $this->assertNotNull($user, 'User should be provisioned in Kimai');
        $this->assertSame($email, $user->getEmail());
        $this->assertTrue($user->hasRole('ROLE_USER'));
        
        // Step 4: Access another Kimai endpoint with the same JWT
        $client->request('GET', '/en/profile/' . $user->getUserIdentifier() . '/edit', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        // Kimai redirects to wizard if user data is not fully configured
        $this->assertResponseRedirects('/en/wizard/intro', 302, 'Authenticated user should be redirected to wizard');
    }

    public function testCrossServiceWorkspaceSwitch(): void
    {
        $client = static::createClient();
        $factory = $this->getFactory();
        $container = static::getContainer();
        
        $affineId = 'e2e-ws-' . uniqid();
        $email = 'e2e-ws-' . uniqid() . '@example.com';
        
        // Step 1: Login with workspace-1 and ROLE_USER
        $jwt1 = $factory->generateValidJwt($affineId, $email, 'workspace-1', ['ROLE_USER']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt1,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);
        
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('ROLE_USER'));
        
        // Step 2: Switch to workspace-2 with ROLE_ADMIN
        $jwt2 = $factory->generateValidJwt($affineId, $email, 'workspace-2', ['ROLE_ADMIN']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt2,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        // Re-fetch user to see updated role
        $updatedUser = $userRepo->findOneBy(['email' => $email]);
        
        $this->assertNotNull($updatedUser);
        $this->assertTrue($updatedUser->hasRole('ROLE_ADMIN'), 'Role should be updated after workspace switch');
    }

    public function testTokenExpirationFlow(): void
    {
        $client = static::createClient();
        $factory = $this->getFactory();
        
        // Step 1: Create expired JWT
        $expiredJwt = $factory->generateExpiredJwt('e2e-expired-' . uniqid(), 'expired@example.com');
        
        // Step 2: Try to access protected resource with expired token
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $expiredJwt,
        ]);
        
        // Should return 401 Unauthorized
        $this->assertResponseStatusCodeSame(401, 'Expired token should be rejected');
        
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Token expired', $content);
    }

    public function testInvalidTokenSignatureRejection(): void
    {
        $client = static::createClient();
        $factory = $this->getFactory();
        
        // Step 1: Create JWT with invalid signature
        $invalidJwt = $factory->generateInvalidSignatureJwt('e2e-fake-' . uniqid(), 'fake@example.com');
        
        // Step 2: Try to access protected resource
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $invalidJwt,
        ]);
        
        // Should return 401 Unauthorized
        $this->assertResponseStatusCodeSame(401, 'Invalid signature should be rejected');
        
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Invalid token signature', $content);
    }

    public function testMultipleRequestsWithSameToken(): void
    {
        $client = static::createClient();
        $factory = $this->getFactory();
        $container = static::getContainer();
        
        $affineId = 'e2e-multi-' . uniqid();
        $email = 'e2e-multi-' . uniqid() . '@example.com';
        
        $jwt = $factory->generateValidJwt($affineId, $email, 'workspace-123', ['ROLE_USER']);
        
        // Step 1: First request - user should be provisioned
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        // Step 2: Second request - should reuse existing user
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        // Step 3: Third request - verify no duplicates
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        // Verify only one user was created
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $users = $userRepo->findBy(['email' => $email]);
        
        $this->assertCount(1, $users, 'Should not create duplicate users across multiple requests');
    }

    public function testUnauthorizedAccessWithoutToken(): void
    {
        $client = static::createClient();
        
        // Try to access protected resource without any token
        $client->request('GET', '/en/dashboard/');
        
        // Should redirect to login
        $this->assertResponseRedirects('/en/login', 302, 'Should redirect to login without token');
    }

    public function testRoleBasedAccessControl(): void
    {
        $client = static::createClient();
        $factory = $this->getFactory();
        $container = static::getContainer();
        
        // Step 1: Create user with basic ROLE_USER
        $affineId = 'e2e-rbac-' . uniqid();
        $email = 'e2e-rbac-' . uniqid() . '@example.com';
        
        $userJwt = $factory->generateValidJwt($affineId, $email, 'workspace-123', ['ROLE_USER']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $userJwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);
        
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('ROLE_USER'));
        $this->assertFalse($user->hasRole('ROLE_SUPER_ADMIN'), 'User should not have admin privileges initially');
        
        // Step 2: Escalate to ROLE_SUPER_ADMIN via new JWT
        $adminJwt = $factory->generateValidJwt($affineId, $email, 'workspace-123', ['ROLE_SUPER_ADMIN']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminJwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        // Re-fetch to see updated role
        $updatedUser = $userRepo->findOneBy(['email' => $email]);
        
        $this->assertNotNull($updatedUser);
        $this->assertTrue($updatedUser->hasRole('ROLE_SUPER_ADMIN'), 'User role should be updated to admin');
    }
}
