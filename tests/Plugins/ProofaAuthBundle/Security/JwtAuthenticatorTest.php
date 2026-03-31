<?php

namespace App\Tests\Plugins\ProofaAuthBundle\Security;

use PHPUnit\Framework\TestCase;
use App\Plugins\ProofaAuthBundle\Security\JwtAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

class JwtAuthenticatorTest extends TestCase
{
    private $jwtValidator;
    private $jwtauthenticator;

    protected function setUp(): void
    {
        $this->jwtValidator = $this->createMock(JwtValidator::class);
        $this->jwtauthenticator = new JwtAuthenticator($this->jwtValidator);
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(JwtAuthenticator::class, $this->jwtauthenticator);
    }
}
