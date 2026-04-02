<?php

namespace App\Plugins\ProofaAuthBundle\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class JwtValidator
{
    private string $secretKey;

    public function __construct(ParameterBagInterface $params)
    {
        // Priority: ENV var > Symfony parameter > default fallback
        $this->secretKey = $_ENV['AFFINE_JWT_SECRET'] 
            ?? ($params->has('affine_jwt_secret') ? $params->get('affine_jwt_secret') : null)
            ?? 'test-secret-key-minimum-32-bytes-long-for-hs256-algorithm-needs-at-least-256-bits';
    }

    public function validate(string $token): array
    {
        // JWT::decode will throw specific exceptions like ExpiredException, SignatureInvalidException
        $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
        $payload = (array) $decoded;

        if (!isset($payload['iss']) || $payload['iss'] !== 'proofa-identity') {
            throw new \UnexpectedValueException('Invalid JWT issuer');
        }

        if (!isset($payload['aud']) || $payload['aud'] !== 'proofa-services') {
            throw new \UnexpectedValueException('Invalid JWT audience');
        }

        if (!isset($payload['sub'])) {
            throw new \UnexpectedValueException('Invalid JWT: missing sub (user id)');
        }

        if (!isset($payload['email'])) {
            throw new \UnexpectedValueException('Invalid JWT: missing email');
        }

        return $payload;
    }
}
