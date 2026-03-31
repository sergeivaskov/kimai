<?php

namespace App\Tests\Plugins\ProofaAuthBundle\EventSubscriber;

use PHPUnit\Framework\TestCase;
use App\Plugins\ProofaAuthBundle\EventSubscriber\TenantSwitchingSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use App\Plugins\ProofaAuthBundle\Security\JwtValidator;

class TenantSwitchingSubscriberTest extends TestCase
{
    private $entityManager;
    private $jwtValidator;
    private $tenantswitchingsubscriber;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->jwtValidator = $this->createMock(JwtValidator::class);
        $this->tenantswitchingsubscriber = new TenantSwitchingSubscriber($this->entityManager, $this->jwtValidator);
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(TenantSwitchingSubscriber::class, $this->tenantswitchingsubscriber);
    }
}
