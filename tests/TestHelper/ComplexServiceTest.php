<?php

namespace App\Tests\TestHelper;

use PHPUnit\Framework\TestCase;
use App\TestHelper\ComplexService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class ComplexServiceTest extends TestCase
{
    private em;
    private requestStack;
    private logger;
    private complexservice;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->complexservice = new ComplexService($this->em, $this->requestStack, $this->logger);
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(ComplexService::class, $this->complexservice);
    }
}
