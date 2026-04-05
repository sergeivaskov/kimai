<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

class IdempotencyGuardTest extends TestCase
{
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;
    private IdempotencyGuard $guard;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->guard = new IdempotencyGuard($this->connection, $this->logger);
    }

    public function testCheckAndMarkReturnsTrueForNewMutation(): void
    {
        $this->connection->expects($this->once())
            ->method('insert')
            ->with('proofa_processed_mutations', $this->callback(function (array $data) {
                return $data['mutation_id'] === 'mut-001'
                    && $data['workspace_id'] === 'ws-1'
                    && $data['entity_type'] === 'user'
                    && $data['entity_id'] === 'ent-1'
                    && $data['version_vector'] === '{"v":1}';
            }));

        $result = $this->guard->checkAndMark('mut-001', 'ws-1', 'user', 'ent-1', ['v' => 1]);
        $this->assertTrue($result);
    }

    public function testCheckAndMarkReturnsFalseForDuplicate(): void
    {
        $exception = $this->createMock(UniqueConstraintViolationException::class);

        $this->connection->expects($this->once())
            ->method('insert')
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Duplicate mutation_id detected, skipping', $this->callback(function (array $ctx) {
                return $ctx['mutation_id'] === 'mut-dup' && $ctx['workspace_id'] === 'ws-1';
            }));

        $result = $this->guard->checkAndMark('mut-dup', 'ws-1', 'user', 'ent-1', []);
        $this->assertFalse($result);
    }

    public function testGetVersionVectorReturnsArrayWhenFound(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(['version_vector' => '{"node1":3,"node2":5}']);

        $result = $this->guard->getVersionVector('project', 'proj-1');
        $this->assertSame(['node1' => 3, 'node2' => 5], $result);
    }

    public function testGetVersionVectorReturnsNullWhenNotFound(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $result = $this->guard->getVersionVector('project', 'proj-unknown');
        $this->assertNull($result);
    }
}
