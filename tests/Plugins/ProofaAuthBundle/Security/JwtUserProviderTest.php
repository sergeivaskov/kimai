<?php

namespace App\Tests\Plugins\ProofaAuthBundle\Security;

use PHPUnit\Framework\TestCase;
use App\Plugins\ProofaAuthBundle\Security\JwtUserProvider;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class JwtUserProviderTest extends TestCase
{
    private $entityManager;
    private $jwtuserprovider;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->jwtuserprovider = new JwtUserProvider($this->entityManager);
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(JwtUserProvider::class, $this->jwtuserprovider);
    }
}
