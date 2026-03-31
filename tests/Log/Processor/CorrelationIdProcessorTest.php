<?php

namespace App\Tests\Log\Processor;

use PHPUnit\Framework\TestCase;
use App\ProofaCoreBundle\Log\Processor\CorrelationIdProcessor;
use Monolog\LogRecord;
use Monolog\Level;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class CorrelationIdProcessorTest extends TestCase
{
    private $requestStack;
    private $processor;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->processor = new CorrelationIdProcessor($this->requestStack);
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(CorrelationIdProcessor::class, $this->processor);
    }

    public function testAddsCorrelationIdWhenPresentInRequest(): void
    {
        $correlationId = 'test-correlation-id-123';
        
        $request = $this->createMock(Request::class);
        $request->attributes = new \Symfony\Component\HttpFoundation\ParameterBag(['_correlation_id' => $correlationId]);
        
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );
        
        $processedRecord = ($this->processor)($record);
        
        $this->assertArrayHasKey('correlation_id', $processedRecord->extra);
        $this->assertEquals($correlationId, $processedRecord->extra['correlation_id']);
    }

    public function testDoesNotAddCorrelationIdWhenNoRequest(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );
        
        $processedRecord = ($this->processor)($record);
        
        $this->assertArrayNotHasKey('correlation_id', $processedRecord->extra);
    }

    public function testDoesNotAddCorrelationIdWhenNotPresentInRequest(): void
    {
        $request = $this->createMock(Request::class);
        $request->attributes = new \Symfony\Component\HttpFoundation\ParameterBag([]);
        
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );
        
        $processedRecord = ($this->processor)($record);
        
        $this->assertArrayNotHasKey('correlation_id', $processedRecord->extra);
    }
}
