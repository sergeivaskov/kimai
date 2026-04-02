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
final class Version20260401122806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {

        $this->addSql('CREATE SEQUENCE proofa_user_mapping_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE proofa_user_mapping (id INT NOT NULL, kimai_user_id INT NOT NULL, affine_id VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5964F011CFF2DD07 ON proofa_user_mapping (affine_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5964F011B0141BE9 ON proofa_user_mapping (kimai_user_id)');
        $this->addSql('ALTER TABLE proofa_user_mapping ADD CONSTRAINT FK_5964F011B0141BE9 FOREIGN KEY (kimai_user_id) REFERENCES kimai2_users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {

        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE proofa_user_mapping_id_seq CASCADE');
        $this->addSql('ALTER TABLE proofa_user_mapping DROP CONSTRAINT FK_5964F011B0141BE9');
        $this->addSql('DROP TABLE proofa_user_mapping');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
