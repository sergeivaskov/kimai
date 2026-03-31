<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CorrelationIdLoggingTest extends WebTestCase
{
    public function testCorrelationIdIsAddedToRequestAttributes(): void
    {
        $client = static::createClient();
        $correlationId = 'test-integration-' . uniqid();
        
        $crawler = $client->request(
            'GET',
            '/api/ping',
            [],
            [],
            ['HTTP_X_CORRELATION_ID' => $correlationId]
        );
        
        $response = $client->getResponse();
        
        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 401]),
            'Expected status 200 or 401, got ' . $response->getStatusCode()
        );
        
        $request = $client->getRequest();
        
        $this->assertTrue(
            $request->attributes->has('_correlation_id'),
            'Request should have _correlation_id attribute'
        );
        
        $this->assertEquals(
            $correlationId,
            $request->attributes->get('_correlation_id'),
            'Correlation ID should match the provided header'
        );
    }

    public function testCorrelationIdIsGeneratedWhenNotProvided(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/ping');
        
        $response = $client->getResponse();
        
        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 401]),
            'Expected status 200 or 401, got ' . $response->getStatusCode()
        );
        
        $request = $client->getRequest();
        
        $this->assertTrue(
            $request->attributes->has('_correlation_id'),
            'Request should have auto-generated _correlation_id attribute'
        );
        
        $correlationId = $request->attributes->get('_correlation_id');
        
        $this->assertNotEmpty($correlationId, 'Generated correlation ID should not be empty');
        
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $correlationId,
            "Generated correlation_id should be a valid UUID v4"
        );
    }
}
