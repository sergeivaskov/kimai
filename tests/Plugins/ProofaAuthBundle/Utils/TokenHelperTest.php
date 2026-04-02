<?php

namespace App\Tests\Plugins\ProofaAuthBundle\Utils;

use PHPUnit\Framework\TestCase;
use App\Plugins\ProofaAuthBundle\Utils\TokenHelper;


class TokenHelperTest extends TestCase
{

    private $tokenhelper;

    protected function setUp(): void
    {

        $this->tokenhelper = new TokenHelper();
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(TokenHelper::class, $this->tokenhelper);
    }
}
