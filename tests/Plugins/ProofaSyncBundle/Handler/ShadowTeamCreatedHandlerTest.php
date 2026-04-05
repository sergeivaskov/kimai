<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Handler;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Handler\ShadowTeamCreatedHandler;
use App\Plugins\ProofaSyncBundle\Message\ShadowTeamCreatedMessage;
use App\Plugins\ProofaSyncBundle\Service\IdempotencyGuard;
use App\Plugins\ProofaSyncBundle\Service\ShadowTeamManager;
use Psr\Log\LoggerInterface;

class ShadowTeamCreatedHandlerTest extends TestCase
{
    private IdempotencyGuard&MockObject $guard;
    private ShadowTeamManager&MockObject $manager;
    private LoggerInterface&MockObject $logger;
    private ShadowTeamCreatedHandler $handler;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(IdempotencyGuard::class);
        $this->manager = $this->createMock(ShadowTeamManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new ShadowTeamCreatedHandler($this->guard, $this->manager, $this->logger);
    }

    private function makeMessage(): ShadowTeamCreatedMessage
    {
        return ShadowTeamCreatedMessage::fromArray([
            'event_type' => 'ShadowTeamCreated',
            'mutation_id' => 'mut-st1',
            'workspace_id' => 'ws-1',
            'timestamp' => '2026-04-05T00:00:00Z',
            'correlation_id' => 'cor-1',
            'document_id' => 'doc-1',
            'user_ids' => ['u1', 'u2'],
            'teamlead_ids' => ['u1'],
        ]);
    }

    public function testHandleCreatesTeam(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(true);
        $this->manager->expects($this->once())
            ->method('createOrUpdateShadowTeam')
            ->with('doc-1', ['u1', 'u2'], ['u1']);

        $this->handler->handle($this->makeMessage());
    }

    public function testHandleDuplicateSkips(): void
    {
        $this->guard->expects($this->once())->method('checkAndMark')->willReturn(false);
        $this->manager->expects($this->never())->method('createOrUpdateShadowTeam');

        $this->handler->handle($this->makeMessage());
    }
}
