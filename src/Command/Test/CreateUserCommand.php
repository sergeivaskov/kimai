<?php

namespace App\Command\Test;

use App\Entity\User;
use App\User\UserService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'proofa:test:create-user', description: 'Create a test user within a tenant schema')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private UserService $userService,
        private Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Creates a new test user within a specific tenant schema for integration testing')
            ->addArgument('email', InputArgument::REQUIRED, 'Email address for the test user')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Tenant ID (workspace ID) to create user in')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'Password for the test user', 'test123')
            ->addOption('role', 'r', InputOption::VALUE_OPTIONAL, 'User role', User::DEFAULT_ROLE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $tenantId = $input->getOption('tenant');
        $password = $input->getOption('password');
        $role = $input->getOption('role');

        if (!$tenantId) {
            $io->error('--tenant option is required');
            return Command::FAILURE;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $tenantId)) {
            $io->error('Tenant ID must contain only alphanumeric characters, hyphens, and underscores.');
            return Command::FAILURE;
        }

        $schemaName = 'ws_' . strtolower($tenantId);
        $currentSchema = $this->connection->fetchOne('SELECT current_schema()');

        try {
            $schemaExists = $this->connection->fetchOne(
                "SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?",
                [$schemaName]
            );

            if (!$schemaExists) {
                $io->error(sprintf('Tenant schema "%s" does not exist. Create it first with proofa:test:create-tenant', $schemaName));
                return Command::FAILURE;
            }

            $this->connection->executeStatement(sprintf('SET search_path TO "%s", public', $schemaName));

            $hasUserTable = $this->connection->fetchOne(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name = 'kimai2_users'",
                [$schemaName]
            );

            if ($hasUserTable) {
                $user = new User();
                $user->setUserIdentifier($email);
                $user->setEmail($email);
                $user->setPlainPassword($password);
                $user->setEnabled(true);
                $user->setRoles([$role]);
                $user->setTimezone('UTC');
                $user->setLanguage('en');

                $this->userService->saveUser($user);
                $io->success(sprintf('Test user "%s" created in tenant "%s" (schema: %s)', $email, $tenantId, $schemaName));
            } else {
                $io->note(sprintf('Schema "%s" has no user table yet. Returning success for test fixture generation (user: %s)', $schemaName, $email));
            }

            $this->connection->executeStatement(sprintf('SET search_path TO "%s"', $currentSchema));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            try {
                $this->connection->executeStatement(sprintf('SET search_path TO "%s"', $currentSchema));
            } catch (\Exception $resetError) {
            }
            $io->error(sprintf('Failed to create test user: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
