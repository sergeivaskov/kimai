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
        // In a real app, this should be loaded from env vars
        // We'll use the same default secret as AFFiNE for dev
        $this->secretKey = $params->has('affine_jwt_secret') 
            ? $params->get('affine_jwt_secret') 
            : 'default_secret_key_for_development_only';
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

        return $payload;
    }
}
