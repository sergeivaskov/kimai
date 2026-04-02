<?php

namespace App\Tests\Plugins\ProofaAuthBundle\Security;

use PHPUnit\Framework\TestCase;
use App\Plugins\ProofaAuthBundle\Security\JwtValidator;
use App\Tests\Plugins\ProofaAuthBundle\TestDataFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class JwtValidatorTest extends TestCase
{
    private JwtValidator $validator;
    private TestDataFactory $factory;
    private string $jwtSecret;

    protected function setUp(): void
    {
        $this->jwtSecret = $_ENV['AFFINE_JWT_SECRET'] ?? 'test-secret-key-minimum-32-bytes-long-for-hs256-algorithm-needs-at-least-256-bits';
        
        $params = new ParameterBag([
            'affine_jwt_secret' => $this->jwtSecret
        ]);
        
        $this->validator = new JwtValidator($params);
        
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->factory = new TestDataFactory($em);
    }

    public function testValidateSuccessfullyDecodesValidJwt(): void
    {
        $userId = 'test-user-123';
        $email = 'test@example.com';
        $workspaceId = 'workspace-456';
        
        $jwt = $this->factory->generateValidJwt($userId, $email, $workspaceId);
        
        $payload = $this->validator->validate($jwt);
        
        $this->assertSame($userId, $payload['sub']);
        $this->assertSame($email, $payload['email']);
        $this->assertSame('proofa-identity', $payload['iss']);
        $this->assertSame('proofa-services', $payload['aud']);
    }

    public function testValidateRejectsExpiredJwt(): void
    {
        $this->expectException(ExpiredException::class);
        
        $jwt = $this->factory->generateExpiredJwt('user-123', 'test@example.com');
        
        $this->validator->validate($jwt);
    }

    public function testValidateRejectsInvalidSignature(): void
    {
        $this->expectException(SignatureInvalidException::class);
        
        $jwt = $this->factory->generateInvalidSignatureJwt('user-123', 'test@example.com');
        
        $this->validator->validate($jwt);
    }

    public function testValidateRejectsJwtWithMissingSub(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('missing sub');
        
        $payload = [
            'email' => 'test@example.com',
            'iss' => 'proofa-identity',
            'aud' => 'proofa-services',
            'iat' => time(),
            'exp' => time() + 900,
        ];
        
        $jwt = JWT::encode($payload, $this->jwtSecret, 'HS256');
        
        $this->validator->validate($jwt);
    }

    public function testValidateRejectsJwtWithMissingEmail(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('missing email');
        
        $payload = [
            'sub' => 'user-123',
            'iss' => 'proofa-identity',
            'aud' => 'proofa-services',
            'iat' => time(),
            'exp' => time() + 900,
        ];
        
        $jwt = JWT::encode($payload, $this->jwtSecret, 'HS256');
        
        $this->validator->validate($jwt);
    }

    public function testValidateRejectsJwtWithInvalidIssuer(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid JWT issuer');
        
        $payload = [
            'sub' => 'user-123',
            'email' => 'test@example.com',
            'iss' => 'malicious-issuer',
            'aud' => 'proofa-services',
            'iat' => time(),
            'exp' => time() + 900,
        ];
        
        $jwt = JWT::encode($payload, $this->jwtSecret, 'HS256');
        
        $this->validator->validate($jwt);
    }

    public function testValidateRejectsJwtWithInvalidAudience(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid JWT audience');
        
        $payload = [
            'sub' => 'user-123',
            'email' => 'test@example.com',
            'iss' => 'proofa-identity',
            'aud' => 'wrong-audience',
            'iat' => time(),
            'exp' => time() + 900,
        ];
        
        $jwt = JWT::encode($payload, $this->jwtSecret, 'HS256');
        
        $this->validator->validate($jwt);
    }
}
