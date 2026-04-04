<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * @version 2.x
 */
final class Version20260402131836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add affine_id to users, projects, activities, teams for Multi-Tenant Architecture';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_activities ADD COLUMN IF NOT EXISTS affine_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_kimai2_activities_affine_id ON kimai2_activities (affine_id)');
        $this->addSql('ALTER TABLE kimai2_projects ADD COLUMN IF NOT EXISTS affine_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_kimai2_projects_affine_id ON kimai2_projects (affine_id)');
        $this->addSql('ALTER TABLE kimai2_teams ADD COLUMN IF NOT EXISTS affine_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_kimai2_teams_affine_id ON kimai2_teams (affine_id)');
        $this->addSql('ALTER TABLE kimai2_users ADD COLUMN IF NOT EXISTS affine_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_kimai2_users_affine_id ON kimai2_users (affine_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_kimai2_teams_affine_id');
        $this->addSql('ALTER TABLE kimai2_teams DROP COLUMN IF EXISTS affine_id');
        $this->addSql('DROP INDEX IF EXISTS uniq_kimai2_projects_affine_id');
        $this->addSql('ALTER TABLE kimai2_projects DROP COLUMN IF EXISTS affine_id');
        $this->addSql('DROP INDEX IF EXISTS uniq_kimai2_activities_affine_id');
        $this->addSql('ALTER TABLE kimai2_activities DROP COLUMN IF EXISTS affine_id');
        $this->addSql('DROP INDEX IF EXISTS uniq_kimai2_users_affine_id');
        $this->addSql('ALTER TABLE kimai2_users DROP COLUMN IF EXISTS affine_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
