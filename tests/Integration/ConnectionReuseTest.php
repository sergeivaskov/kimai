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

class ConnectionReuseTest extends KernelTestCase
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

    public function testConnectionStateIsReset(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('CREATE SCHEMA IF NOT EXISTS ws_test_a');
        $conn->executeStatement('CREATE SCHEMA IF NOT EXISTS ws_test_b');

        // Очищаем кэш списка схем, чтобы новые схемы подхватились
        $cache = static::getContainer()->get('cache.app');
        $cache->deleteItem('tenant:schemas:list');

        // 1. Request for Workspace A
        $requestA = Request::create('/api/projects');
        $requestA->attributes->set('workspace_id', 'test_a');
        
        $eventA = new RequestEvent(self::$kernel, $requestA, HttpKernelInterface::MAIN_REQUEST);
        $this->switchingSubscriber->onKernelRequest($eventA);
        
        $this->assertTrue($requestA->attributes->get('tenant_switched'));
        $this->assertEquals('ws_test_a', $conn->fetchOne('SHOW search_path'));

        // Simulate Response
        $responseEventA = new ResponseEvent(self::$kernel, $requestA, HttpKernelInterface::MAIN_REQUEST, new Response());
        $this->resetSubscriber->onKernelResponse($responseEventA);

        // Verify reset
        $this->assertStringContainsString('public', $conn->fetchOne('SHOW search_path'));
        $this->assertNull($requestA->attributes->get('tenant_switched'));

        // 2. Request for Workspace B
        $requestB = Request::create('/api/projects');
        $requestB->attributes->set('workspace_id', 'test_b');

        $eventB = new RequestEvent(self::$kernel, $requestB, HttpKernelInterface::MAIN_REQUEST);
        $this->switchingSubscriber->onKernelRequest($eventB);

        $this->assertTrue($requestB->attributes->get('tenant_switched'));
        $this->assertEquals('ws_test_b', $conn->fetchOne('SHOW search_path'));

        // Simulate Terminate
        $requestB->attributes->set('tenant_switched', true);
        $terminateEventB = new TerminateEvent(self::$kernel, $requestB, new Response());
        $this->resetSubscriber->onKernelTerminate($terminateEventB);

        // Verify reset
        $this->assertStringContainsString('public', $conn->fetchOne('SHOW search_path'));

        // Cleanup
        $conn->executeStatement('DROP SCHEMA IF EXISTS ws_test_a CASCADE');
        $conn->executeStatement('DROP SCHEMA IF EXISTS ws_test_b CASCADE');
    }
}
