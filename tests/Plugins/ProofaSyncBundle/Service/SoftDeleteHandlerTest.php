<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Service\SoftDeleteHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class SoftDeleteHandlerTest extends TestCase
{
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;
    private SoftDeleteHandler $handler;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new SoftDeleteHandler($this->connection, $this->logger);
    }

    public function testArchiveProject(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE kimai2_projects SET is_archived = true WHERE affine_id = :affine_id',
                ['affine_id' => 'proj-1']
            )
            ->willReturn(1);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Entity archived', $this->callback(fn($ctx) => $ctx['table'] === 'kimai2_projects'));

        $this->handler->archiveEntity('project', 'proj-1');
    }

    public function testArchiveActivity(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE kimai2_activities SET is_archived = true WHERE affine_id = :affine_id',
                ['affine_id' => 'act-1']
            )
            ->willReturn(1);

        $this->handler->archiveEntity('activity', 'act-1');
    }

    public function testArchiveEntityNotFound(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturn(0);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Entity not found for archiving', $this->anything());

        $this->handler->archiveEntity('project', 'proj-missing');
    }

    public function testUnsupportedEntityTypeLogsWarning(): void
    {
        $this->connection->expects($this->never())
            ->method('executeStatement');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Unsupported entity type for soft delete', ['type' => 'user']);

        $this->handler->archiveEntity('user', 'usr-1');
    }
}
