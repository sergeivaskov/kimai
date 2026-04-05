<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Service\SyncEventDispatcher;
use App\Plugins\ProofaSyncBundle\Handler\UserCreatedHandler;
use App\Plugins\ProofaSyncBundle\Handler\ProjectCreatedHandler;
use App\Plugins\ProofaSyncBundle\Handler\EntityDeletedHandler;
use App\Plugins\ProofaSyncBundle\Handler\ShadowTeamCreatedHandler;
use App\Plugins\ProofaSyncBundle\Handler\ShadowTeamDeletedHandler;
use Psr\Log\LoggerInterface;

class SyncEventDispatcherTest extends TestCase
{
    private UserCreatedHandler&MockObject $userHandler;
    private ProjectCreatedHandler&MockObject $projectHandler;
    private EntityDeletedHandler&MockObject $entityDeletedHandler;
    private ShadowTeamCreatedHandler&MockObject $shadowTeamCreatedHandler;
    private ShadowTeamDeletedHandler&MockObject $shadowTeamDeletedHandler;
    private LoggerInterface&MockObject $logger;
    private SyncEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->userHandler = $this->createMock(UserCreatedHandler::class);
        $this->projectHandler = $this->createMock(ProjectCreatedHandler::class);
        $this->entityDeletedHandler = $this->createMock(EntityDeletedHandler::class);
        $this->shadowTeamCreatedHandler = $this->createMock(ShadowTeamCreatedHandler::class);
        $this->shadowTeamDeletedHandler = $this->createMock(ShadowTeamDeletedHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dispatcher = new SyncEventDispatcher(
            $this->userHandler,
            $this->projectHandler,
            $this->entityDeletedHandler,
            $this->shadowTeamCreatedHandler,
            $this->shadowTeamDeletedHandler,
            $this->logger
        );
    }

    private function baseEvent(string $type, array $extra = []): array
    {
        return array_merge([
            'event_type' => $type,
            'mutation_id' => 'mut-1',
            'workspace_id' => 'ws-1',
            'timestamp' => '2026-04-05T00:00:00Z',
            'correlation_id' => 'cor-1',
            'entity_id' => 'ent-1',
            'affine_id' => 'aff-1',
            'payload' => ['email' => 'test@test.com'],
        ], $extra);
    }

    public function testDispatchUserCreated(): void
    {
        $this->userHandler->expects($this->once())->method('handle');
        $this->dispatcher->dispatch($this->baseEvent('UserCreated'));
    }

    public function testDispatchProjectCreated(): void
    {
        $this->projectHandler->expects($this->once())->method('handle');
        $this->dispatcher->dispatch($this->baseEvent('ProjectCreated', ['payload' => ['name' => 'Proj']]));
    }

    public function testDispatchEntityDeleted(): void
    {
        $this->entityDeletedHandler->expects($this->once())->method('handle');
        $this->dispatcher->dispatch($this->baseEvent('EntityDeleted', ['entity_type' => 'project']));
    }

    public function testDispatchShadowTeamCreated(): void
    {
        $this->shadowTeamCreatedHandler->expects($this->once())->method('handle');
        $this->dispatcher->dispatch($this->baseEvent('ShadowTeamCreated', [
            'document_id' => 'doc-1',
            'user_ids' => ['u1'],
            'teamlead_ids' => ['u1'],
        ]));
    }

    public function testDispatchShadowTeamDeleted(): void
    {
        $this->shadowTeamDeletedHandler->expects($this->once())->method('handle');
        $this->dispatcher->dispatch($this->baseEvent('ShadowTeamDeleted', ['document_id' => 'doc-1']));
    }

    public function testDispatchUnknownEventTypeLogsWarning(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Unknown event type', ['type' => 'FooBar']);

        $this->dispatcher->dispatch($this->baseEvent('FooBar'));
    }
}
