<?php

namespace App\Tests\Plugins\ProofaAuthBundle\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Plugins\ProofaAuthBundle\Service\UserProvisioningService;

class UserProvisioningServiceTest extends WebTestCase
{
    private $userprovisioningservice;

    public function testProvisionNewUser(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        
        $service = $container->get(UserProvisioningService::class);
        $this->assertInstanceOf(UserProvisioningService::class, $service);
        
        $jwtPayload = [
            'sub' => 'affine-user-test-' . uniqid(),
            'email' => 'test-provision-' . uniqid() . '@example.com',
            'kimai_role' => 'ROLE_USER'
        ];
        
        $user = $service->provisionUser($jwtPayload);
        
        $this->assertNotNull($user);
        $this->assertSame($jwtPayload['email'], $user->getEmail());
        $this->assertTrue($user->hasRole('ROLE_USER'));
    }
    
    public function testProvisionUserIdempotency(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        
        $service = $container->get(UserProvisioningService::class);
        
        $jwtPayload = [
            'sub' => 'affine-user-idempotent-' . uniqid(),
            'email' => 'test-idempotent-' . uniqid() . '@example.com',
            'kimai_role' => 'ROLE_USER'
        ];
        
        $user1 = $service->provisionUser($jwtPayload);
        $user2 = $service->provisionUser($jwtPayload);
        
        $this->assertSame($user1->getId(), $user2->getId());
    }
}
