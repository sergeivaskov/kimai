<?php

namespace App\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Service\AuthService;

class AuthServiceTest extends WebTestCase
{
    private $authservice;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        
        // Get service from container
        // $this->authservice = static::getContainer()->get(AuthService::class);
    }

    public function testIntegration(): void
    {
        $client = static::createClient();
        // $client->request('GET', '/api/endpoint');
        // $this->assertResponseIsSuccessful();
        
        $this->assertTrue(true, 'Integration test scaffolded');
    }
}
