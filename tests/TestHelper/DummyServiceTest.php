<?php

namespace App\Tests\TestHelper;

use PHPUnit\Framework\TestCase;
use App\TestHelper\DummyService;
use Psr\Log\LoggerInterface;

class DummyServiceTest extends TestCase
{
    private logger;
    private dummyservice;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dummyservice = new DummyService($this->logger);
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(DummyService::class, $this->dummyservice);
    }
}
