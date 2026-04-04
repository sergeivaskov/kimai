<?php

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TenantIsolationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTenantDataIsolation(): void
    {
        $conn = $this->entityManager->getConnection();

        $conn->executeStatement('CREATE SCHEMA IF NOT EXISTS ws_isolation_a');
        $conn->executeStatement('CREATE SCHEMA IF NOT EXISTS ws_isolation_b');

        $conn->executeStatement('CREATE TABLE IF NOT EXISTS ws_isolation_a.test_data (id SERIAL PRIMARY KEY, value TEXT)');
        $conn->executeStatement('CREATE TABLE IF NOT EXISTS ws_isolation_b.test_data (id SERIAL PRIMARY KEY, value TEXT)');

        $conn->executeStatement("INSERT INTO ws_isolation_a.test_data (value) VALUES ('alpha_secret')");
        $conn->executeStatement("INSERT INTO ws_isolation_b.test_data (value) VALUES ('beta_secret')");

        // Switch to schema A and verify only A's data is visible
        $conn->executeStatement('SET search_path TO "ws_isolation_a"');
        $dataA = $conn->fetchAllAssociative('SELECT value FROM test_data');
        $this->assertCount(1, $dataA);
        $this->assertEquals('alpha_secret', $dataA[0]['value']);

        // Switch to schema B and verify only B's data is visible
        $conn->executeStatement('SET search_path TO "ws_isolation_b"');
        $dataB = $conn->fetchAllAssociative('SELECT value FROM test_data');
        $this->assertCount(1, $dataB);
        $this->assertEquals('beta_secret', $dataB[0]['value']);

        // Verify NO cross-contamination
        $conn->executeStatement('SET search_path TO "ws_isolation_a"');
        $crossCheck = $conn->fetchAllAssociative('SELECT value FROM test_data');
        $this->assertCount(1, $crossCheck);
        $this->assertNotEquals('beta_secret', $crossCheck[0]['value']);

        // Repeat 50 times to simulate connection pool reuse
        for ($i = 0; $i < 50; $i++) {
            $schema = $i % 2 === 0 ? 'ws_isolation_a' : 'ws_isolation_b';
            $expectedValue = $i % 2 === 0 ? 'alpha_secret' : 'beta_secret';

            $conn->executeStatement(sprintf('SET search_path TO "%s"', $schema));
            $result = $conn->fetchOne('SELECT value FROM test_data');
            $this->assertEquals($expectedValue, $result, "Data leak detected at iteration $i");
        }

        // Cleanup
        $conn->executeStatement('SET search_path TO public');
        $conn->executeStatement('DROP SCHEMA IF EXISTS ws_isolation_a CASCADE');
        $conn->executeStatement('DROP SCHEMA IF EXISTS ws_isolation_b CASCADE');
    }
}
