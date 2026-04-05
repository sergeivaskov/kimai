<?php

namespace App\Plugins\ProofaSyncBundle\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class ShadowTeamManager
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    public function createOrUpdateShadowTeam(
        string $documentAffineId,
        array $userAffineIds,
        array $teamleadAffineIds
    ): void {
        $teamName = "shadow_doc_{$documentAffineId}_team";

        // Найти или создать Shadow Team через DBAL
        $teamId = $this->connection->fetchOne(
            'SELECT id FROM kimai2_teams WHERE name = :name',
            ['name' => $teamName]
        );

        if (!$teamId) {
            $this->connection->insert('kimai2_teams', [
                'name' => $teamName,
                'affine_id' => $documentAffineId,
            ]);
            
            // fetchOne to get the id of the newly inserted team since lastInsertId can be tricky with postgres sequences if not explicitly provided
            $teamId = $this->connection->fetchOne(
                'SELECT id FROM kimai2_teams WHERE name = :name',
                ['name' => $teamName]
            );
            $this->logger->info("Shadow Team created", ['team_name' => $teamName]);
        }

        // Очистить существующих участников (идемпотентность)
        $this->connection->executeStatement(
            'DELETE FROM kimai2_users_teams WHERE team_id = :team_id',
            ['team_id' => $teamId]
        );

        // Добавить пользователей
        foreach ($userAffineIds as $affineId) {
            $userId = $this->connection->fetchOne(
                'SELECT id FROM kimai2_users WHERE affine_id = :affine_id',
                ['affine_id' => $affineId]
            );

            if (!$userId) {
                $this->logger->warning("User not found for affine_id", ['affine_id' => $affineId]);
                continue;
            }

            $isTeamlead = in_array($affineId, $teamleadAffineIds, true);
            $this->connection->insert('kimai2_users_teams', [
                'user_id' => $userId,
                'team_id' => $teamId,
                'teamlead' => $isTeamlead ? 1 : 0,
            ]);
        }

        $this->logger->info("Shadow Team updated", [
            'team_name' => $teamName,
            'users_count' => count($userAffineIds),
        ]);
    }

    public function deleteShadowTeam(string $documentAffineId): void
    {
        $teamName = "shadow_doc_{$documentAffineId}_team";

        $teamId = $this->connection->fetchOne(
            'SELECT id FROM kimai2_teams WHERE name = :name',
            ['name' => $teamName]
        );

        if ($teamId) {
            $this->connection->executeStatement(
                'DELETE FROM kimai2_users_teams WHERE team_id = :team_id',
                ['team_id' => $teamId]
            );
            $this->connection->executeStatement(
                'DELETE FROM kimai2_teams WHERE id = :id',
                ['id' => $teamId]
            );
            $this->logger->info("Shadow Team deleted", ['team_name' => $teamName]);
        }
    }
}
