<?php

namespace App\Plugins\ProofaAuthBundle\Utils;

/**
 * Simple utility class for testing workflow orchestrator
 */
class TokenHelper
{
    public function extractTokenFromHeader(?string $header): ?string
    {
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }

    public function validateTokenFormat(string $token): bool
    {
        $parts = explode('.', $token);
        return count($parts) === 3;
    }
}
