<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Handler;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Handler\ShadowTeamDeletedHandler;
use App\Plugins\ProofaSyncBundle\Message\ShadowTeamDeletedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use App\Plugins\ProofaSyncBundle\Service\ShadowTeamManager;
use Psr\Log\LoggerInterface;

class ShadowTeamDeletedHandlerTest extends TestCase
{
    private IdempotencyGuard&MockObject $guard;
    private ShadowTeamManager&MockObject $manager;
    private LoggerInterface&MockObject $logger;
    private ShadowTeamDeletedHandler $handler;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(IdempotencyGuard::class);
        $this->manager = $this->createMock(ShadowTeamManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new ShadowTeamDeletedHandler($this->guard, $this->manager, $this->logger);
    }

    private function makeMessage(): ShadowTeamDeletedMessage
    {
        return ShadowTeamDeletedMessage::fromArray([
            'event_type' => 'ShadowTeamDeleted',
            'mutation_id' => 'mut-std1',
            'workspace_id' => 'ws-1',
            'timestamp' => '2026-04-05T00:00:00Z',
            'correlation_id' => 'cor-1',
            'document_id' => 'doc-del-1',
        ]);
    }

    public function testHandleDeletesTeam(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(true);
        $this->manager->expects($this->once())
            ->method('deleteShadowTeam')
            ->with('doc-del-1');

        $this->handler->handle($this->makeMessage());
    }

    public function testHandleDuplicateSkips(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(false);
        $this->manager->expects($this->never())->method('deleteShadowTeam');

        $this->handler->handle($this->makeMessage());
    }
}
