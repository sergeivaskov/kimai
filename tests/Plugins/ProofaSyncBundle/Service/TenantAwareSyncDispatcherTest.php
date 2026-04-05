<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Service\TenantAwareSyncDispatcher;
use App\Plugins\ProofaSyncBundle\Service\SyncEventDispatcher;
use Doctrine\DBAL\Connection;

class TenantAwareSyncDispatcherTest extends TestCase
{
    private SyncEventDispatcher&MockObject $innerDispatcher;
    private Connection&MockObject $connection;
    private TenantAwareSyncDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->innerDispatcher = $this->createMock(SyncEventDispatcher::class);
        $this->connection = $this->createMock(Connection::class);
        $this->dispatcher = new TenantAwareSyncDispatcher($this->innerDispatcher, $this->connection);
    }

    public function testDispatchSetsSchemaAndCommits(): void
    {
        $event = ['workspace_id' => 'abc123', 'event_type' => 'UserCreated'];

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1);

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('SET LOCAL search_path TO "ws_abc123"');

        $this->innerDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($event);

        $this->connection->expects($this->once())->method('commit');

        $this->dispatcher->dispatch($event);
    }

    public function testDispatchThrowsOnMissingWorkspaceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing workspace_id in sync event');

        $this->dispatcher->dispatch(['event_type' => 'UserCreated']);
    }

    public function testDispatchThrowsOnNonExistentSchema(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->dispatcher->dispatch(['workspace_id' => 'unknown', 'event_type' => 'UserCreated']);
    }

    public function testDispatchRollsBackOnError(): void
    {
        $this->connection->expects($this->once())->method('fetchOne')->willReturn(1);
        $this->connection->expects($this->once())->method('beginTransaction');

        $this->innerDispatcher->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('handler failed'));

        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->dispatcher->dispatch(['workspace_id' => 'ws1', 'event_type' => 'UserCreated']);
    }

    public function testDispatchSanitizesWorkspaceId(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('SET LOCAL search_path TO "ws_abc_def_123"');

        $this->innerDispatcher->expects($this->once())->method('dispatch');
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');

        $this->dispatcher->dispatch(['workspace_id' => 'abc-def.123', 'event_type' => 'UserCreated']);
    }
}
