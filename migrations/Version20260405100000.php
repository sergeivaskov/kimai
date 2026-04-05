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
final class Version20260405100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add proofa_processed_mutations table and is_archived field for Soft Delete';
    }

    public function up(Schema $schema): void
    {
        // 1.5: Create proofa_processed_mutations table
        $this->addSql('CREATE TABLE IF NOT EXISTS proofa_processed_mutations (
            mutation_id VARCHAR(255) PRIMARY KEY,
            workspace_id VARCHAR(255) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id VARCHAR(255) NOT NULL,
            version_vector JSONB NOT NULL,
            processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_workspace ON proofa_processed_mutations (workspace_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_entity ON proofa_processed_mutations (entity_type, entity_id)');

        // 1.6: Add is_archived to kimai2_projects and kimai2_activities
        $this->addSql('ALTER TABLE kimai2_projects ADD COLUMN IF NOT EXISTS is_archived BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_kimai2_projects_not_archived ON kimai2_projects (is_archived) WHERE is_archived = false');

        $this->addSql('ALTER TABLE kimai2_activities ADD COLUMN IF NOT EXISTS is_archived BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_kimai2_activities_not_archived ON kimai2_activities (is_archived) WHERE is_archived = false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_kimai2_activities_not_archived');
        $this->addSql('ALTER TABLE kimai2_activities DROP COLUMN IF EXISTS is_archived');

        $this->addSql('DROP INDEX IF EXISTS idx_kimai2_projects_not_archived');
        $this->addSql('ALTER TABLE kimai2_projects DROP COLUMN IF EXISTS is_archived');

        $this->addSql('DROP TABLE IF EXISTS proofa_processed_mutations');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
