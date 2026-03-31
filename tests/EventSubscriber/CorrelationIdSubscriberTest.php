<?php

namespace App\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use App\ProofaCoreBundle\EventSubscriber\CorrelationIdSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class CorrelationIdSubscriberTest extends TestCase
{
    private $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new CorrelationIdSubscriber();
    }

    public function testInitialization(): void
    {
        $this->assertInstanceOf(CorrelationIdSubscriber::class, $this->subscriber);
    }

    public function testSubscribedEvents(): void
    {
        $events = CorrelationIdSubscriber::getSubscribedEvents();
        
        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertEquals(['onKernelRequest', 256], $events[KernelEvents::REQUEST]);
    }

    public function testGeneratesCorrelationIdWhenNotPresent(): void
    {
        $request = new Request();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        
        $this->subscriber->onKernelRequest($event);
        
        $this->assertTrue($request->headers->has('X-Correlation-ID'));
        $this->assertTrue($request->attributes->has('_correlation_id'));
        
        $correlationId = $request->headers->get('X-Correlation-ID');
        $this->assertNotEmpty($correlationId);
        $this->assertEquals($correlationId, $request->attributes->get('_correlation_id'));
        
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $correlationId
        );
    }

    public function testUsesExistingCorrelationIdFromHeader(): void
    {
        $existingId = 'existing-correlation-id-456';
        $request = new Request();
        $request->headers->set('X-Correlation-ID', $existingId);
        
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        
        $this->subscriber->onKernelRequest($event);
        
        $this->assertEquals($existingId, $request->headers->get('X-Correlation-ID'));
        $this->assertEquals($existingId, $request->attributes->get('_correlation_id'));
    }
}
