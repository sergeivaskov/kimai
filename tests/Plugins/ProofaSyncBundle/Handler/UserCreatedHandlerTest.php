<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Handler;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Handler\UserCreatedHandler;
use App\Plugins\ProofaSyncBundle\Message\UserCreatedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class UserCreatedHandlerTest extends TestCase
{
    private IdempotencyGuard&MockObject $guard;
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;
    private UserCreatedHandler $handler;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(IdempotencyGuard::class);
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new UserCreatedHandler($this->guard, $this->connection, $this->logger);
    }

    private function makeMessage(array $overrides = []): UserCreatedMessage
    {
        return UserCreatedMessage::fromArray(array_merge([
            'event_type' => 'UserCreated',
            'mutation_id' => 'mut-u1',
            'workspace_id' => 'ws-1',
            'timestamp' => '2026-04-05T00:00:00Z',
            'correlation_id' => 'cor-1',
            'entity_id' => 'ent-u1',
            'affine_id' => 'aff-u1',
            'payload' => ['email' => 'user@example.com'],
            'version_vector' => ['v' => 1],
        ], $overrides));
    }

    public function testHandleNewUserUpdatesAffineId(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(true);
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE kimai2_users SET affine_id = :affine_id WHERE email = :email AND affine_id IS NULL',
                ['affine_id' => 'aff-u1', 'email' => 'user@example.com']
            )
            ->willReturn(1);

        $this->handler->handle($this->makeMessage());
    }

    public function testHandleDuplicateSkips(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(false);
        $this->connection->expects($this->never())->method('executeStatement');

        $this->handler->handle($this->makeMessage());
    }

    public function testHandleNoUserFoundLogsWarning(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(true);
        $this->connection->expects($this->once())->method('executeStatement')->willReturn(0);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'UserCreated: no user found with matching email or affine_id already set',
                $this->anything()
            );

        $this->handler->handle($this->makeMessage());
    }
}
