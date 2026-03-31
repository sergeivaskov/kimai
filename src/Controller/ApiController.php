<?php

namespace App\Controller;

use App\Service\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiController
{
    public function __construct(
        private AuthService $authService
    ) {}

    public function index(string $token, string $tenantId): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'token' => $token, 'tenant' => $tenantId]);
    }
}
