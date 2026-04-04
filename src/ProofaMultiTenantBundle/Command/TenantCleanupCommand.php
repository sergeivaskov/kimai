<?php

namespace App\ProofaMultiTenantBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tenant:cleanup',
    description: 'Permanently deletes archived tenant schemas older than 90 days.'
)]
class TenantCleanupCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Number of days to keep archived schemas', 90)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without actually deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');
        $dryRun = $input->getOption('dry-run');

        $conn = $this->entityManager->getConnection();

        $sql = "
            SELECT workspace_id, schema_name 
            FROM public.proofa_tenant_schemas 
            WHERE status = 'archived' 
              AND deleted_at < NOW() - INTERVAL '$days days'
        ";

        $schemasToDelete = $conn->fetchAllAssociative($sql);

        if (empty($schemasToDelete)) {
            $io->success('No archived schemas found for deletion.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Found %d schemas to delete', count($schemasToDelete)));

        if ($dryRun) {
            $io->note('DRY RUN MODE: No schemas will be deleted.');
            foreach ($schemasToDelete as $row) {
                $io->text(sprintf('- %s (Workspace: %s)', $row['schema_name'], $row['workspace_id']));
            }
            return Command::SUCCESS;
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($schemasToDelete as $row) {
            $schema = $row['schema_name'];
            $workspaceId = $row['workspace_id'];
            
            $io->text(sprintf('Deleting schema %s...', $schema));

            $conn->beginTransaction();
            try {
                // Drop schema
                $conn->executeStatement(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));

                // Delete from tracking table
                $conn->executeStatement('DELETE FROM public.proofa_tenant_schemas WHERE workspace_id = :workspace_id', [
                    'workspace_id' => $workspaceId
                ]);

                $conn->commit();
                $deletedCount++;

                $this->logger->info('tenant_schema_hard_deleted', [
                    'workspace_id' => $workspaceId,
                    'schema' => $schema
                ]);
            } catch (\Throwable $e) {
                $conn->rollBack();
                $failedCount++;
                
                $this->logger->critical('tenant_schema_hard_delete_failed', [
                    'workspace_id' => $workspaceId,
                    'schema' => $schema,
                    'error' => $e->getMessage()
                ]);
                $io->error(sprintf('Failed to delete schema %s: %s', $schema, $e->getMessage()));
            }
        }

        if ($failedCount > 0) {
            $io->warning(sprintf('Cleanup finished with errors. Deleted: %d, Failed: %d.', $deletedCount, $failedCount));
            return Command::FAILURE;
        }

        $io->success(sprintf('Successfully deleted %d schemas.', $deletedCount));
        return Command::SUCCESS;
    }
}
