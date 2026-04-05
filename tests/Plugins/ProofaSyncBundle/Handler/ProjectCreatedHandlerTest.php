<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Handler;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Handler\ProjectCreatedHandler;
use App\Plugins\ProofaSyncBundle\Message\ProjectCreatedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class ProjectCreatedHandlerTest extends TestCase
{
    private IdempotencyGuard&MockObject $guard;
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;
    private ProjectCreatedHandler $handler;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(IdempotencyGuard::class);
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new ProjectCreatedHandler($this->guard, $this->connection, $this->logger);
    }

    private function makeMessage(array $overrides = []): ProjectCreatedMessage
    {
        return ProjectCreatedMessage::fromArray(array_merge([
            'event_type' => 'ProjectCreated',
            'mutation_id' => 'mut-p1',
            'workspace_id' => 'ws-1',
            'timestamp' => '2026-04-05T00:00:00Z',
            'correlation_id' => 'cor-1',
            'entity_id' => 'ent-p1',
            'affine_id' => 'aff-p1',
            'payload' => ['name' => 'Test Project', 'customer_id' => 5],
            'version_vector' => ['v' => 1],
        ], $overrides));
    }

    public function testHandleDuplicateSkips(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(false);
        $this->connection->expects($this->never())->method('insert');
        $this->handler->handle($this->makeMessage());
    }

    public function testHandleCreatesNewProject(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(true);

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT id FROM kimai2_projects WHERE affine_id = :affine_id', ['affine_id' => 'aff-p1'])
            ->willReturn(false);

        $this->connection->expects($this->once())
            ->method('insert')
            ->with('kimai2_projects', $this->callback(function (array $data) {
                return $data['name'] === 'Test Project'
                    && $data['customer_id'] === 5
                    && $data['affine_id'] === 'aff-p1'
                    && $data['is_archived'] === false;
            }));

        $this->handler->handle($this->makeMessage());
    }

    public function testHandleUpdatesExistingProject(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(true);

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT id FROM kimai2_projects WHERE affine_id = :affine_id', ['affine_id' => 'aff-p1'])
            ->willReturn(42);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE kimai2_projects SET name = :name WHERE affine_id = :affine_id',
                ['name' => 'Test Project', 'affine_id' => 'aff-p1']
            );

        $this->connection->expects($this->never())->method('insert');

        $this->handler->handle($this->makeMessage());
    }

    public function testHandleFallsBackToFirstCustomerWhenNotProvided(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(true);

        $msg = $this->makeMessage(['payload' => ['name' => 'No Customer Proj']]);

        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(99, false);

        $this->connection->expects($this->once())
            ->method('insert')
            ->with('kimai2_projects', $this->callback(fn($d) => $d['customer_id'] === 99));

        $this->handler->handle($msg);
    }

    public function testHandleNoCustomerFoundLogsError(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(true);

        $msg = $this->makeMessage(['payload' => ['name' => 'Orphan']]);

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn(false);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('No customer found in Kimai to assign project', $this->anything());

        $this->connection->expects($this->never())->method('insert');

        $this->handler->handle($msg);
    }
}
