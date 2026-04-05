<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates proofa_tenant_schemas table in public schema for tracking tenant lifecycle';
    }

    public function up(Schema $schema): void
    {
        // Create table in public schema
        $this->addSql('
            CREATE TABLE IF NOT EXISTS public.proofa_tenant_schemas (
                workspace_id VARCHAR(255) PRIMARY KEY,
                schema_name VARCHAR(255) UNIQUE NOT NULL,
                status VARCHAR(50) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
            )
        ');

        // Backfill existing schemas
        $this->addSql("
            INSERT INTO public.proofa_tenant_schemas (workspace_id, schema_name, status, created_at)
            SELECT 
                substring(schema_name from 4), 
                schema_name, 
                'active', 
                NOW() 
            FROM information_schema.schemata 
            WHERE schema_name LIKE 'ws_%'
            ON CONFLICT (workspace_id) DO NOTHING
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public.proofa_tenant_schemas');
    }
}
