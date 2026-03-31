<?php

namespace App\Tests\Plugins\ProofaAuthBundle\Security;

use PHPUnit\Framework\TestCase;
use App\Plugins\ProofaAuthBundle\Security\JwtValidator;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class JwtValidatorTest extends TestCase
{
    private $params;
    private $jwtvalidator;

    protected function setUp(): void
    {
        $this->params = $this->createMock(ParameterBagInterface::class);
        $this->jwtvalidator = new JwtValidator($this->params);
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(JwtValidator::class, $this->jwtvalidator);
    }
}
