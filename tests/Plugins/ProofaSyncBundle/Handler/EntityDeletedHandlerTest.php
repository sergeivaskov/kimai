<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Handler;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Handler\EntityDeletedHandler;
use App\Plugins\ProofaSyncBundle\Message\EntityDeletedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use App\Plugins\ProofaSyncBundle\Service\SoftDeleteHandler;
use Psr\Log\LoggerInterface;

class EntityDeletedHandlerTest extends TestCase
{
    private IdempotencyGuard&MockObject $guard;
    private SoftDeleteHandler&MockObject $softDelete;
    private LoggerInterface&MockObject $logger;
    private EntityDeletedHandler $handler;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(IdempotencyGuard::class);
        $this->softDelete = $this->createMock(SoftDeleteHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new EntityDeletedHandler($this->guard, $this->softDelete, $this->logger);
    }

    private function makeMessage(string $entityType = 'project'): EntityDeletedMessage
    {
        return EntityDeletedMessage::fromArray([
            'event_type' => 'EntityDeleted',
            'mutation_id' => 'mut-del-1',
            'workspace_id' => 'ws-1',
            'timestamp' => '2026-04-05T00:00:00Z',
            'correlation_id' => 'cor-1',
            'entity_type' => $entityType,
            'entity_id' => 'ent-del-1',
            'affine_id' => 'aff-del-1',
        ]);
    }

    public function testHandleCallsSoftDelete(): void
    {
        $this->guard->expects($this->once())
            ->method('checkAndMark')
            ->with('mut-del-1', 'ws-1', 'project_deleted', 'aff-del-1', [])
            ->willReturn(true);

        $this->softDelete->expects($this->once())
            ->method('archiveEntity')
            ->with('project', 'aff-del-1');

        $this->handler->handle($this->makeMessage());
    }

    public function testHandleDuplicateSkips(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(false);
        $this->softDelete->expects($this->never())->method('archiveEntity');

        $this->handler->handle($this->makeMessage());
    }
}
