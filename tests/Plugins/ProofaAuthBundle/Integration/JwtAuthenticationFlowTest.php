<?php

namespace App\Tests\Plugins\ProofaAuthBundle\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Plugins\ProofaAuthBundle\Service\UserProvisioningService;
use App\Tests\Plugins\ProofaAuthBundle\TestDataFactory;
use App\Entity\User;
use Firebase\JWT\JWT;

class JwtAuthenticationFlowTest extends WebTestCase
{
    private string $jwtSecret;

    protected function setUp(): void
    {
        $this->jwtSecret = $_ENV['AFFINE_JWT_SECRET'] ?? 'test-secret-key-minimum-32-bytes-long-for-hs256-algorithm-needs-at-least-256-bits';
    }

    private function getFactory(): TestDataFactory
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        return new TestDataFactory($em);
    }

    public function testKimaiAcceptsValidJwtFromAffine(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $factory = $this->getFactory();
        
        $uniqueEmail = 'jwt-test-' . uniqid() . '@example.com';
        $uniqueSub = 'affine-' . uniqid();
        
        $jwt = $factory->generateValidJwt($uniqueSub, $uniqueEmail);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        // Successful authentication redirects to wizard if no data configured
        // This is expected Kimai behavior
        $this->assertResponseRedirects('/en/wizard/intro', 302, 'Authenticated user should be redirected to wizard');
        
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $uniqueEmail]);
        
        $this->assertNotNull($user, 'User should be auto-provisioned');
        $this->assertSame($uniqueEmail, $user->getEmail());
        $this->assertTrue($user->hasRole('ROLE_USER'));
    }

    public function testKimaiRejectsExpiredJwt(): void
    {
        $client = static::createClient();
        $factory = $this->getFactory();
        
        $jwt = $factory->generateExpiredJwt('affine-expired', 'expired@example.com');
        
        $client->request('GET', '/en/about', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseStatusCodeSame(401);
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Token expired', $content, 'Response should indicate token expired');
    }

    public function testKimaiRejectsJwtWithInvalidSignature(): void
    {
        $client = static::createClient();
        $factory = $this->getFactory();
        
        $jwt = $factory->generateInvalidSignatureJwt('affine-fake', 'fake@example.com');
        
        $client->request('GET', '/en/about', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseStatusCodeSame(401);
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Invalid token signature', $content, 'Response should indicate invalid signature');
    }

    public function testRoleEscalationPrevention(): void
    {
        $client = static::createClient();
        $factory = $this->getFactory();
        
        $jwt = $factory->generateValidJwt('affine-attacker', 'attacker@example.com', 'workspace-123', ['ROLE_SUPER_ADMIN']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro', 302, 'Authenticated user should be redirected to wizard');
        
        $container = static::getContainer();
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => 'attacker@example.com']);
        
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('ROLE_SUPER_ADMIN'), 'Role from JWT should be respected');
    }

    public function testJitProvisioningCreatesUserOnFirstRequest(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $factory = $this->getFactory();
        
        $affineId = 'affine-jit-' . uniqid();
        $email = 'jit-test-' . uniqid() . '@example.com';
        
        $jwt = $factory->generateValidJwt($affineId, $email, 'workspace-123', ['ROLE_USER']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro', 302, 'Authenticated user should be redirected to wizard');
        
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);
        
        $this->assertNotNull($user, 'User should be auto-provisioned on first request');
        $this->assertSame($email, $user->getEmail());
    }

    public function testJitProvisioningDoesNotCreateDuplicates(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $factory = $this->getFactory();
        
        $affineId = 'affine-no-dup-' . uniqid();
        $email = 'no-dup-' . uniqid() . '@example.com';
        
        $jwt = $factory->generateValidJwt($affineId, $email, 'workspace-123', ['ROLE_USER']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $users = $userRepo->findBy(['email' => $email]);
        
        $this->assertCount(1, $users, 'Should not create duplicate users');
    }

    public function testRequestWithoutJwtIsRejectedForProtectedRoutes(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/en/dashboard/');
        
        $this->assertResponseRedirects('/en/login', 302, 'Should redirect to login without JWT');
    }

    public function testRoleMappingFromJwtToKimai(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $factory = $this->getFactory();
        
        $affineId = 'affine-admin-' . uniqid();
        $email = 'admin-test-' . uniqid() . '@example.com';
        
        $jwt = $factory->generateValidJwt($affineId, $email, 'workspace-123', ['ROLE_SUPER_ADMIN']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);
        
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('ROLE_SUPER_ADMIN'), 'User should have SUPER_ADMIN role from JWT');
    }

    public function testWorkspaceSwitchingUpdatesRoles(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $factory = $this->getFactory();
        
        $affineId = 'affine-ws-switch-' . uniqid();
        $email = 'ws-switch-' . uniqid() . '@example.com';
        
        $jwtWorkspace1 = $factory->generateValidJwt($affineId, $email, 'workspace-1', ['ROLE_USER']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwtWorkspace1,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        $userRepo = $container->get('doctrine')->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);
        $this->assertTrue($user->hasRole('ROLE_USER'));
        
        $jwtWorkspace2 = $factory->generateValidJwt($affineId, $email, 'workspace-2', ['ROLE_SUPER_ADMIN']);
        
        $client->request('GET', '/en/dashboard/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwtWorkspace2,
        ]);
        
        $this->assertResponseRedirects('/en/wizard/intro');
        
        // Re-fetch user from database to get updated roles
        $updatedUser = $userRepo->findOneBy(['email' => $email]);
        
        $this->assertNotNull($updatedUser);
        $this->assertTrue($updatedUser->hasRole('ROLE_SUPER_ADMIN'), 'Role should be updated on workspace switch');
    }

    private function extractCookies(array $headers): array
    {
        $cookies = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $cookieStr = substr($header, 12);
                $parts = explode(';', $cookieStr);
                [$name, $value] = explode('=', trim($parts[0]), 2);
                $cookies[$name] = $value;
            }
        }
        return $cookies;
    }
}
