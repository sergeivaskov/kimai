<?php

namespace App\Command\Test;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'proofa:test:create-tenant', description: 'Create a test tenant (PostgreSQL schema)')]
final class CreateTenantCommand extends Command
{
    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Creates a new PostgreSQL schema for multi-tenant isolation in tests')
            ->addArgument('tenantId', InputArgument::REQUIRED, 'Unique identifier for the tenant (e.g., test_tenant_123)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantId = $input->getArgument('tenantId');

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $tenantId)) {
            $io->error('Tenant ID must contain only alphanumeric characters, hyphens, and underscores.');
            return Command::FAILURE;
        }

        $schemaName = 'ws_' . strtolower($tenantId);

        try {
            $schemaExists = $this->connection->fetchOne(
                "SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?",
                [$schemaName]
            );

            if ($schemaExists) {
                $io->note(sprintf('Tenant schema "%s" already exists. Skipping creation.', $schemaName));
                return Command::SUCCESS;
            }

            $this->connection->executeStatement(sprintf('CREATE SCHEMA "%s"', $schemaName));
            
            $publicTables = $this->connection->fetchAllAssociative(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name NOT LIKE 'doctrine_%'"
            );
            
            $copiedTables = 0;
            if (!empty($publicTables)) {
                foreach ($publicTables as $row) {
                    $tableName = $row['table_name'];
                    try {
                        $this->connection->executeStatement(
                            sprintf('CREATE TABLE "%s"."%s" (LIKE public."%s" INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES)', $schemaName, $tableName, $tableName)
                        );
                        $copiedTables++;
                    } catch (\Exception $e) {
                        $io->warning(sprintf('Skipped table "%s": %s', $tableName, $e->getMessage()));
                    }
                }
                
                $configTables = ['kimai2_configuration', 'kimai2_defaults'];
                foreach ($configTables as $tableName) {
                    $tableExists = $this->connection->fetchOne(
                        "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
                        [$tableName]
                    );
                    if ($tableExists) {
                        try {
                            $rowCount = $this->connection->fetchOne(
                                sprintf('SELECT count(*) FROM public."%s"', $tableName)
                            );
                            
                            if ($rowCount > 0) {
                                $this->connection->executeStatement(
                                    sprintf('INSERT INTO "%s"."%s" SELECT * FROM public."%s"', $schemaName, $tableName, $tableName)
                                );
                            }
                        } catch (\Exception $e) {
                            $io->warning(sprintf('Could not copy data for "%s": %s', $tableName, $e->getMessage()));
                        }
                    }
                }
            }
            
            $message = $copiedTables > 0 
                ? sprintf('Tenant schema "%s" created with %d tables copied from public.', $schemaName, $copiedTables)
                : sprintf('Tenant schema "%s" created (empty - no tables in public schema yet).', $schemaName);
            
            $io->success($message);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Failed to create tenant schema: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
