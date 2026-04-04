<?php

namespace App\Tests\Integration;

use App\ProofaMultiTenantBundle\EventSubscriber\TenantSwitchingSubscriber;
use App\ProofaMultiTenantBundle\EventSubscriber\TenantStateResetSubscriber;
use App\ProofaMultiTenantBundle\Service\TenantMetricsService;
use App\ProofaMultiTenantBundle\Service\WorkspaceIdValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Response;

class WarmupBenchmarkTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TenantSwitchingSubscriber $switchingSubscriber;
    private TenantStateResetSubscriber $resetSubscriber;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        
        $validator = $container->get(WorkspaceIdValidator::class);
        $cache = $container->get('cache.app');
        $logger = $container->get(LoggerInterface::class);

        $metricsService = $container->get(TenantMetricsService::class);

        $this->switchingSubscriber = new TenantSwitchingSubscriber($this->entityManager, $validator, $cache, $logger, $metricsService);
        $this->resetSubscriber = new TenantStateResetSubscriber($this->entityManager, $logger, $metricsService);
    }

    public function testWarmupLatency(): void
    {
        $conn = $this->entityManager->getConnection();
        $workspaceId = 'benchmark_test_' . uniqid();
        $schemaName = 'ws_' . $workspaceId;
        
        // 1. Provision schema without warmup
        $conn->executeStatement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $schemaName));
        $conn->executeStatement(sprintf('CREATE TABLE "%s".kimai2_users (id INT PRIMARY KEY)', $schemaName));
        $conn->executeStatement(sprintf('CREATE TABLE "%s".kimai2_projects (id INT PRIMARY KEY)', $schemaName));
        $conn->executeStatement(sprintf('CREATE TABLE "%s".kimai2_timesheet (id INT PRIMARY KEY)', $schemaName));

        // Clear cache
        $cache = static::getContainer()->get('cache.app');
        $cache->deleteItem('tenant:schemas:list');

        // 2. Measure first request (cold start)
        $request1 = Request::create('/api/projects');
        $request1->attributes->set('workspace_id', $workspaceId);
        
        $event1 = new RequestEvent(self::$kernel, $request1, HttpKernelInterface::MAIN_REQUEST);
        
        $startCold = microtime(true);
        $this->switchingSubscriber->onKernelRequest($event1);
        // Simulate a simple query
        $conn->executeQuery(sprintf('SELECT 1 FROM "%s".kimai2_users LIMIT 1', $schemaName));
        $coldLatency = (microtime(true) - $startCold) * 1000;

        // Reset state
        $responseEvent1 = new ResponseEvent(self::$kernel, $request1, HttpKernelInterface::MAIN_REQUEST, new Response());
        $this->resetSubscriber->onKernelResponse($responseEvent1);

        // 3. Measure second request (warm)
        $request2 = Request::create('/api/projects');
        $request2->attributes->set('workspace_id', $workspaceId);
        
        $event2 = new RequestEvent(self::$kernel, $request2, HttpKernelInterface::MAIN_REQUEST);
        
        $startWarm = microtime(true);
        $this->switchingSubscriber->onKernelRequest($event2);
        // Simulate a simple query
        $conn->executeQuery(sprintf('SELECT 1 FROM "%s".kimai2_users LIMIT 1', $schemaName));
        $warmLatency = (microtime(true) - $startWarm) * 1000;

        // Reset state
        $responseEvent2 = new ResponseEvent(self::$kernel, $request2, HttpKernelInterface::MAIN_REQUEST, new Response());
        $this->resetSubscriber->onKernelResponse($responseEvent2);

        // Print results
        echo sprintf("\nCold Start Latency: %.2f ms\n", $coldLatency);
        echo sprintf("Warm Latency: %.2f ms\n", $warmLatency);

        // The warm request should be significantly faster or at least very fast (< 20ms)
        $this->assertLessThan(100, $coldLatency, 'Cold start latency is too high');
        $this->assertLessThan(20, $warmLatency, 'Warm latency is too high');

        // Cleanup
        $conn->executeStatement(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schemaName));
    }
}
