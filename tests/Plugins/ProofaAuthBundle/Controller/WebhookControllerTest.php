<?php

namespace App\Tests\Plugins\ProofaAuthBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Plugins\ProofaAuthBundle\Entity\ProofaUserMapping;
use App\Plugins\ProofaAuthBundle\Repository\ProofaUserMappingRepository;
use App\Entity\User;

class WebhookControllerTest extends WebTestCase
{
    private const WEBHOOK_SECRET = 'proofa-internal-secret-key-2024';
    private const WEBHOOK_ENDPOINT = '/auth/internal/webhook/logout';

    public function testWebhookAcceptsRequestWithValidSecret(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        
        $em = $container->get('doctrine')->getManager();
        
        $affineId = 'test-affine-webhook-' . uniqid();
        $email = 'webhook-test-' . uniqid() . '@example.com';
        
        $user = new User();
        $user->setUsername($email);
        $user->setEmail($email);
        $user->setEnabled(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('dummy-password-hash');
        $em->persist($user);
        
        $mapping = new ProofaUserMapping();
        $mapping->setAffineId($affineId);
        $mapping->setKimaiUser($user);
        $em->persist($mapping);
        
        $em->flush();
        
        $client->request('POST', self::WEBHOOK_ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-INTERNAL-SECRET' => self::WEBHOOK_SECRET,
        ], json_encode([
            'user_id' => $affineId,
            'workspace_id' => 'test-workspace-123'
        ]));
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('success', $response['status']);
    }

    public function testWebhookRejectsRequestWithoutSecret(): void
    {
        $client = static::createClient();
        
        $client->request('POST', self::WEBHOOK_ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'user_id' => 'any-user-id',
        ]));
        
        $this->assertResponseStatusCodeSame(401);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Unauthorized', $response['error']);
    }

    public function testWebhookRejectsRequestWithInvalidSecret(): void
    {
        $client = static::createClient();
        
        $client->request('POST', self::WEBHOOK_ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-INTERNAL-SECRET' => 'wrong-secret-key',
        ], json_encode([
            'user_id' => 'any-user-id',
        ]));
        
        $this->assertResponseStatusCodeSame(401);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Unauthorized', $response['error']);
    }

    public function testWebhookHandlesUnknownUser(): void
    {
        $client = static::createClient();
        
        $nonExistentAffineId = 'non-existent-affine-id-' . uniqid();
        
        $client->request('POST', self::WEBHOOK_ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-INTERNAL-SECRET' => self::WEBHOOK_SECRET,
        ], json_encode([
            'user_id' => $nonExistentAffineId,
        ]));
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('ignored', $response['status']);
        $this->assertSame('user not found', $response['reason']);
    }

    public function testWebhookRequiresUserId(): void
    {
        $client = static::createClient();
        
        $client->request('POST', self::WEBHOOK_ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-INTERNAL-SECRET' => self::WEBHOOK_SECRET,
        ], json_encode([
            'workspace_id' => 'some-workspace'
        ]));
        
        $this->assertResponseStatusCodeSame(400);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Missing user_id', $response['error']);
    }
}
