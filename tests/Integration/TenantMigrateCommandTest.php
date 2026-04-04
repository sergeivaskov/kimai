<?php

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class TenantMigrateCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testMigrateSingleSchemaSucceeds(): void
    {
        $conn = $this->entityManager->getConnection();
        $schemaName = 'ws_migrate_test_' . uniqid();

        $conn->executeStatement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $schemaName));

        try {
            $application = new Application(self::$kernel);
            $command = $application->find('app:tenant:migrate');
            $commandTester = new CommandTester($command);

            $commandTester->execute([
                '--schema' => $schemaName,
            ]);

            $this->assertEquals(0, $commandTester->getStatusCode(), 
                'Migration command should succeed. Output: ' . $commandTester->getDisplay());

            // Verify that the schema has Kimai tables
            $tables = $conn->fetchFirstColumn(sprintf(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = '%s' ORDER BY table_name",
                $schemaName
            ));
            $this->assertNotEmpty($tables, 'Schema should have tables after migration');
            $this->assertContains('kimai2_users', $tables, 'Schema should have kimai2_users table');

        } finally {
            $conn->executeStatement(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schemaName));
        }
    }

    public function testMigrateNonExistentSchemaFails(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:tenant:migrate');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            '--schema' => 'ws_nonexistent_schema_xyz',
        ]);

        $this->assertNotEquals(0, $commandTester->getStatusCode());
    }
}
