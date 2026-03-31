<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use App\Controller\ApiController;
use App\Service\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiControllerTest extends TestCase
{
    private $authService;
    private $apicontroller;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthService::class);
        $this->apicontroller = new ApiController($this->authService);
    }

    public function testIndexReturnsCorrectData(): void
    {
        $token = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyXzAwMSIsImVtYWlsIjoiYWRtaW5AcHJvb2ZhLmNvbSIsInRlbmFudF9pZCI6InRlbmFudF8wMDEiLCJ0eXBlIjoiYWNjZXNzIiwiaWF0IjoxNzc0NjI5OTQ1LCJpc3MiOiJwcm9vZmEtaWRlbnRpdHkiLCJhdWQiOiJwcm9vZmEtc2VydmljZXMiLCJleHAiOjE3NzQ2MzM1NDV9.NY5jcoM-ZxYXAo5wFMny8Ib4KchqXZs3tKXnfW2wI30";
        $tenantId = "tenant_001";

        $response = $this->apicontroller->index($token, $tenantId);
        
        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals('ok', $data['status']);
        $this->assertEquals($token, $data['token']);
        $this->assertEquals($tenantId, $data['tenant']);
    }
}
