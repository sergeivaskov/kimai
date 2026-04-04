<?php

namespace App\ProofaMultiTenantBundle\Command;

use App\ProofaMultiTenantBundle\Service\TenantProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tenant:migrate',
    description: 'Applies Doctrine migrations to all existing tenant schemas.'
)]
class TenantMigrateCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantProvisioningService $provisioningService,
        private \App\ProofaMultiTenantBundle\Service\DefaultCustomerService $defaultCustomerService,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Apply migrations only to a specific schema (e.g. ws_123)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the plan without executing migrations')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Do not stop if migration fails for one schema');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $schemaOption = $input->getOption('schema');
        $dryRun = $input->getOption('dry-run');
        $continueOnError = $input->getOption('continue-on-error');

        $conn = $this->entityManager->getConnection();

        // 1. Get schemas
        if ($schemaOption) {
            // SINGLE SCHEMA MODE (runs in current process)
            return $this->migrateSingleSchema($schemaOption, $io, $dryRun);
        }

        // MULTIPLE SCHEMAS MODE (spawns child processes to avoid Doctrine Migrations freezing)
        $schemas = $conn->fetchFirstColumn("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'ws_%'");

        if (empty($schemas)) {
            $io->warning('No tenant schemas found.');
            return Command::SUCCESS;
        }

        $totalSchemas = count($schemas);
        $io->title(sprintf('Migrating %d tenant schemas', $totalSchemas));

        $this->logger->info('tenant_migration_started', [
            'total_schemas' => $totalSchemas,
            'dry_run' => $dryRun
        ]);

        if ($dryRun) {
            $io->note('DRY RUN MODE: No changes will be made.');
        }

        $progressBar = new ProgressBar($output, $totalSchemas);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% | %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        $succeeded = 0;
        $failed = 0;
        $skipped = 0;

        $startTime = microtime(true);

        foreach ($schemas as $schema) {
            $progressBar->setMessage(sprintf('Migrating %s', $schema));
            $progressBar->display();

            if ($dryRun) {
                // In dry-run, we just pretend it succeeded
                $succeeded++;
                $progressBar->advance();
                continue;
            }

            // Spawn child process
            $process = new \Symfony\Component\Process\Process([
                'php',
                'bin/console',
                'app:tenant:migrate',
                '--schema=' . $schema
            ]);
            
            // Increase timeout for slow migrations
            $process->setTimeout(300);
            $process->run();

            if ($process->isSuccessful()) {
                $succeeded++;
            } else {
                $failed++;
                $this->logger->error('tenant_migration_schema_failed', [
                    'schema' => $schema,
                    'status' => 'failed',
                    'error' => $process->getErrorOutput() ?: $process->getOutput()
                ]);

                if (!$continueOnError) {
                    $io->newLine(2);
                    $io->error(sprintf('Migration failed for schema %s: %s', $schema, $process->getErrorOutput() ?: $process->getOutput()));
                    return Command::FAILURE;
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $duration = round(microtime(true) - $startTime, 2);

        $io->success(sprintf(
            'Migration summary: %d succeeded, %d failed, %d skipped. Total time: %ss',
            $succeeded,
            $failed,
            $skipped,
            $duration
        ));

        $this->logger->info('tenant_migration_completed', [
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => $skipped,
            'duration_s' => $duration
        ]);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function migrateSingleSchema(string $schema, SymfonyStyle $io, bool $dryRun): int
    {
        if ($dryRun) {
            $io->success(sprintf('Dry run for %s completed.', $schema));
            return Command::SUCCESS;
        }

        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            $this->provisioningService->applyMigrationsToSchema($schema);
            
            // Ensure default customer exists
            $workspaceId = substr($schema, 3);
            $this->defaultCustomerService->createDefaultCustomer($schema, $workspaceId);
            
            $conn->commit();
            
            $this->logger->info('tenant_migration_schema_success', [
                'schema' => $schema,
                'status' => 'success',
            ]);
            
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $conn->rollBack();
            
            $this->logger->error('tenant_migration_schema_failed', [
                'schema' => $schema,
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);

            $io->error(sprintf('Migration failed for schema %s: %s', $schema, $e->getMessage()));
            return Command::FAILURE;
        } finally {
            // Always reset search path
            try {
                $conn->executeStatement('SET search_path TO "public"');
            } catch (\Exception $e) {
                // Ignore
            }
        }
    }
}
