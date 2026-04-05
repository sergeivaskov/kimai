<?php

namespace App\Tests\Plugins\ProofaSyncBundle\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Plugins\ProofaSyncBundle\Service\ShadowTeamManager;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class ShadowTeamManagerTest extends TestCase
{
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;
    private ShadowTeamManager $manager;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->manager = new ShadowTeamManager($this->connection, $this->logger);
    }

    public function testCreateNewShadowTeam(): void
    {
        $this->connection->expects($this->exactly(3))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(false, 42, 101);

        $insertCall = 0;
        $this->connection->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$insertCall) {
                $insertCall++;
                if ($insertCall === 1) {
                    $this->assertSame('kimai2_teams', $table);
                    $this->assertSame('doc-abc', $data['affine_id']);
                } else {
                    $this->assertSame('kimai2_users_teams', $table);
                    $this->assertSame(101, $data['user_id']);
                    $this->assertSame(42, $data['team_id']);
                }
                return 1;
            });

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM kimai2_users_teams WHERE team_id = :team_id', ['team_id' => 42]);

        $this->manager->createOrUpdateShadowTeam('doc-abc', ['user-1'], []);
    }

    public function testUpdateExistingShadowTeam(): void
    {
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(10, 77);

        $this->connection->expects($this->once())
            ->method('executeStatement');

        $this->connection->expects($this->once())
            ->method('insert')
            ->with('kimai2_users_teams', $this->callback(function (array $data) {
                return $data['user_id'] === 77 && $data['team_id'] === 10 && $data['teamlead'] === 1;
            }));

        $this->manager->createOrUpdateShadowTeam('doc-xyz', ['lead-1'], ['lead-1']);
    }

    public function testDeleteExistingShadowTeam(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn(5);

        $this->connection->expects($this->exactly(2))
            ->method('executeStatement');

        $this->manager->deleteShadowTeam('doc-del');
    }

    public function testDeleteNonExistentTeamDoesNothing(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn(false);

        $this->connection->expects($this->never())
            ->method('executeStatement');

        $this->manager->deleteShadowTeam('doc-none');
    }

    public function testUserNotFoundSkipsWithWarning(): void
    {
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(10, false);

        $this->connection->expects($this->once())
            ->method('executeStatement');

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $this->connection->expects($this->never())
            ->method('insert');

        $this->manager->createOrUpdateShadowTeam('doc-miss', ['unknown-user'], []);
    }
}
