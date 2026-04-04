<?php

namespace App\ProofaMultiTenantBundle\Command;

use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\Team;
use App\ProofaMultiTenantBundle\Service\WorkspaceIdValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tenant:seed',
    description: 'Seeds a tenant schema with test data (Projects, Activities, Teams).'
)]
class TenantSeedCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceIdValidator $validator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Schema to seed (e.g. ws_test_workspace_123)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $schema = $input->getOption('schema');

        if (!$schema) {
            $io->error('You must provide a schema via --schema option.');
            return Command::FAILURE;
        }

        if (!str_starts_with($schema, 'ws_')) {
            $io->error('Schema name must start with "ws_" prefix.');
            return Command::FAILURE;
        }
        $workspaceIdPart = substr($schema, 3);
        if (!$this->validator->validate($workspaceIdPart)) {
            $io->error(sprintf('Invalid workspace ID format in schema name: %s', $workspaceIdPart));
            return Command::FAILURE;
        }

        $conn = $this->entityManager->getConnection();

        // Check if schema exists
        $schemaExists = $conn->fetchOne('SELECT schema_name FROM information_schema.schemata WHERE schema_name = :schema', ['schema' => $schema]);
        if (!$schemaExists) {
            $io->error(sprintf('Schema "%s" does not exist.', $schema));
            return Command::FAILURE;
        }

        // Switch to schema
        $conn->executeStatement(sprintf('SET search_path TO "%s"', $schema));

        $io->title(sprintf('Seeding schema: %s', $schema));

        $conn->beginTransaction();
        try {
            // 1. Teams
            $teamA = new Team('Engineering');
            $teamB = new Team('Marketing');
            
            $this->entityManager->persist($teamA);
            $this->entityManager->persist($teamB);

            // 2. Projects (without Customer, should be auto-assigned by ProjectCustomerSubscriber)
            $project1 = new Project();
            $project1->setName('Internal Tools');
            $project1->setVisible(true);

            $project2 = new Project();
            $project2->setName('Website Redesign');
            $project2->setVisible(true);

            $project3 = new Project();
            $project3->setName('SEO Campaign');
            $project3->setVisible(true);

            $this->entityManager->persist($project1);
            $this->entityManager->persist($project2);
            $this->entityManager->persist($project3);

            // 3. Activities
            $activities = ['Development', 'Design', 'Testing', 'Copywriting', 'Meeting'];
            $activityEntities = [];
            foreach ($activities as $name) {
                $activity = new Activity();
                $activity->setName($name);
                $activity->setVisible(true);
                $this->entityManager->persist($activity);
                $activityEntities[] = $activity;
            }

            $this->entityManager->flush();
            $conn->commit();

            $io->success('Successfully seeded 3 Projects, 5 Activities, 2 Teams.');

            // Verify
            $projectsWithCustomer = $conn->fetchOne('SELECT count(*) FROM kimai2_projects WHERE customer_id IS NOT NULL');
            $io->note(sprintf('Projects with customer assigned: %d', $projectsWithCustomer));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $conn->rollBack();
            $io->error('Seeding failed: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            try {
                $conn->executeStatement('SET search_path TO "public"');
            } catch (\Exception $e) {}
        }
    }
}
