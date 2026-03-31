<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\DBAL\Connection;

class InfrastructureSmokeTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        
        $this->connection = static::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testPostgreSQLIsAccessible(): void
    {
        $result = $this->connection->executeQuery('SELECT 1 as test')->fetchOne();
        
        $this->assertEquals(1, $result, 'PostgreSQL should be accessible');
    }

    public function testPostgreSQLHasCorrectCharset(): void
    {
        $charset = $this->connection->executeQuery(
            "SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = current_database()"
        )->fetchOne();
        
        $this->assertContains($charset, ['UTF8', 'UNICODE'], 'Database should use UTF8 encoding');
    }

    public function testRedisIsAccessible(): void
    {
        $redisUrl = $_ENV['REDIS_URL'] ?? '';
        
        if (empty($redisUrl)) {
            $this->markTestSkipped('REDIS_URL not configured');
        }
        
        $redis = new \Redis();
        
        $parsedUrl = parse_url($redisUrl);
        $host = $parsedUrl['host'] ?? 'localhost';
        $port = $parsedUrl['port'] ?? 6379;
        
        $connected = $redis->connect($host, $port);
        
        $this->assertTrue($connected, 'Should be able to connect to Redis');
        
        $pong = $redis->ping();
        $this->assertTrue($pong, 'Redis should respond to PING');
        
        $redis->close();
    }

    public function testMinIOIsAccessibleFromBackend(): void
    {
        $minioEndpoint = $_ENV['MINIO_ENDPOINT'] ?? '';
        
        if (empty($minioEndpoint)) {
            $this->markTestSkipped('MINIO_ENDPOINT not configured');
        }
        
        $ch = curl_init($minioEndpoint . '/minio/health/live');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertEquals(200, $httpCode, 'MinIO health endpoint should return 200');
    }

    public function testPgBouncerIsUsedForConnection(): void
    {
        $dbUrl = $_ENV['DATABASE_URL'] ?? '';
        
        if (strpos($dbUrl, 'pgbouncer') === false) {
            $this->markTestSkipped('Not using PgBouncer in this environment');
        }
        
        $this->assertStringContainsString('pgbouncer', $dbUrl, 'Should be using PgBouncer');
        $this->assertStringContainsString('6432', $dbUrl, 'Should be using PgBouncer port 6432');
    }

    public function testCanPerformBasicQueryOperations(): void
    {
        $result = $this->connection->executeQuery(
            'SELECT COUNT(*) FROM kimai2_users'
        )->fetchOne();
        
        $this->assertIsNumeric($result, 'Should be able to query kimai2_users table');
        
        $result2 = $this->connection->executeQuery(
            'SELECT 1 + 1 as sum'
        )->fetchOne();
        
        $this->assertEquals(2, $result2, 'Should be able to execute arithmetic operations');
        
        $currentDate = $this->connection->executeQuery(
            'SELECT CURRENT_TIMESTAMP'
        )->fetchOne();
        
        $this->assertNotEmpty($currentDate, 'Should be able to get current timestamp');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
