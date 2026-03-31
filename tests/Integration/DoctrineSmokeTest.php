<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;

class DoctrineSmokeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        
        $this->entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->connection = $this->entityManager->getConnection();
        
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testDatabaseConnectionWorks(): void
    {
        $result = $this->connection->executeQuery('SELECT 1')->fetchOne();
        
        $this->assertEquals(1, $result, 'Should be able to execute simple query');
    }

    public function testPostgreSQLVersionIsCorrect(): void
    {
        $version = $this->connection->executeQuery('SELECT version()')->fetchOne();
        
        $this->assertStringContainsString('PostgreSQL', $version);
        
        preg_match('/PostgreSQL (\d+)/', $version, $matches);
        $majorVersion = isset($matches[1]) ? (int)$matches[1] : 0;
        
        $this->assertGreaterThanOrEqual(15, $majorVersion, 'PostgreSQL version should be 15 or higher');
    }

    public function testAllEntitiesAreMapped(): void
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        
        $this->assertGreaterThan(0, count($metadata), 'Should have at least one entity mapped');
        
        foreach ($metadata as $classMetadata) {
            $tableName = $classMetadata->getTableName();
            
            $tableExists = $this->connection->createSchemaManager()->tablesExist([$tableName]);
            
            $this->assertTrue(
                $tableExists,
                "Table {$tableName} for entity {$classMetadata->getName()} should exist in database"
            );
        }
    }

    public function testCanQueryCoreEntities(): void
    {
        $coreEntities = [
            'kimai2_users',
            'kimai2_teams',
            'kimai2_customers',
            'kimai2_projects',
            'kimai2_activities',
            'kimai2_timesheet'
        ];
        
        foreach ($coreEntities as $tableName) {
            $tableExists = $this->connection->createSchemaManager()->tablesExist([$tableName]);
            
            $this->assertTrue(
                $tableExists,
                "Core table {$tableName} should exist"
            );
            
            $count = $this->connection->executeQuery("SELECT COUNT(*) FROM {$tableName}")->fetchOne();
            
            $this->assertIsNumeric($count, "Should be able to count records in {$tableName}");
        }
    }

    public function testPgBouncerConnectionPooling(): void
    {
        $dbUrl = $_ENV['DATABASE_URL'] ?? '';
        
        if (strpos($dbUrl, 'pgbouncer') !== false) {
            $result = $this->connection->executeQuery('SELECT 1')->fetchOne();
            
            $this->assertEquals(1, $result, 'Should be able to execute queries through PgBouncer');
            
            $this->assertStringContainsString('pgbouncer', $dbUrl, 'DATABASE_URL should reference pgbouncer');
            $this->assertStringContainsString('6432', $dbUrl, 'Should use PgBouncer default port 6432');
        } else {
            $this->markTestSkipped('Not using PgBouncer in this environment');
        }
    }

    public function testDatabaseSchemaIsInSync(): void
    {
        $this->markTestSkipped('Covered by doctrine:schema:validate command in CI/CD');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        if ($this->entityManager && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }
    }
}
