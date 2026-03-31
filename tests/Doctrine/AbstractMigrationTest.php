<?php

declare(strict_types=1);

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Doctrine;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \App\Doctrine\AbstractMigration
 */
final class AbstractMigrationTest extends TestCase
{
    private function createMigration(Connection $connection): AbstractMigration
    {
        $logger = $this->createMock(LoggerInterface::class);
        
        return new class($connection, $logger) extends AbstractMigration {
            public function up(Schema $schema): void
            {
            }

            public function testAddSql(string $sql): void
            {
                $this->addSql($sql);
            }

            public function testTranslateToPostgreSQL(string $sql): string
            {
                $reflection = new ReflectionClass(AbstractMigration::class);
                $method = $reflection->getMethod('translateToPostgreSQL');
                $method->setAccessible(true);

                return $method->invoke($this, $sql);
            }
        };
    }

    /**
     * @dataProvider providePostgreSQLTranslations
     */
    public function testTranslateToPostgreSQL(string $input, string $expected): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $migration = $this->createMigration($connection);
        $result = $migration->testTranslateToPostgreSQL($input);

        self::assertSame($expected, $result);
    }

    public static function providePostgreSQLTranslations(): \Generator
    {
        // Rule 1: TINYINT(1) → BOOLEAN
        yield 'TINYINT(1) to BOOLEAN' => [
            'CREATE TABLE test (active TINYINT(1) NOT NULL)',
            'CREATE TABLE test (active BOOLEAN NOT NULL)',
        ];

        // Rule 2: LONGTEXT → TEXT
        yield 'LONGTEXT to TEXT' => [
            'CREATE TABLE test (description LONGTEXT DEFAULT NULL)',
            'CREATE TABLE test (description TEXT DEFAULT NULL)',
        ];

        yield 'TINYTEXT to TEXT' => [
            'CREATE TABLE test (note TINYTEXT DEFAULT NULL)',
            'CREATE TABLE test (note TEXT DEFAULT NULL)',
        ];

        yield 'MEDIUMTEXT to TEXT' => [
            'CREATE TABLE test (content MEDIUMTEXT DEFAULT NULL)',
            'CREATE TABLE test (content TEXT DEFAULT NULL)',
        ];

        // Rule 3: ENGINE=InnoDB → removed
        yield 'ENGINE=InnoDB removed' => [
            'CREATE TABLE test (id INT) ENGINE = InnoDB',
            'CREATE TABLE test (id INT)',
        ];

        yield 'ENGINE=InnoDB no spaces removed' => [
            'CREATE TABLE test (id INT) ENGINE=InnoDB',
            'CREATE TABLE test (id INT)',
        ];

        // Rule 4: AUTO_INCREMENT → removed
        yield 'AUTO_INCREMENT removed' => [
            'CREATE TABLE test (id INT AUTO_INCREMENT NOT NULL)',
            'CREATE TABLE test (id INT  NOT NULL)',
        ];

        // Rule 5: DC2Type comments → removed
        yield 'DC2Type comment removed' => [
            'CREATE TABLE test (roles LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\')',
            'CREATE TABLE test (roles TEXT NOT NULL)',
        ];

        // Complex real-world example combining multiple rules
        // Note: INDEX definitions are extracted and will be added as separate CREATE INDEX commands
        yield 'Complex migration with multiple rules' => [
            'CREATE TABLE kimai2_invoice_templates_meta (id INT AUTO_INCREMENT NOT NULL, template_id INT NOT NULL, name VARCHAR(50) NOT NULL, value TEXT DEFAULT NULL, visible TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_A165B0555DA0FB8 (template_id), UNIQUE INDEX UNIQ_A165B0555DA0FB85E237E06 (template_id, name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
            'CREATE TABLE kimai2_invoice_templates_meta (id INT  NOT NULL, template_id INT NOT NULL, name VARCHAR(50) NOT NULL, value TEXT DEFAULT NULL, visible BOOLEAN DEFAULT FALSE NOT NULL, PRIMARY KEY(id))',
        ];

        yield 'ALTER TABLE with TINYINT and LONGTEXT' => [
            'ALTER TABLE kimai2_users CHANGE active enabled TINYINT(1) NOT NULL, CHANGE roles roles LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\'',
            'ALTER TABLE kimai2_users ALTER "enabled" TYPE BOOLEAN, ALTER "enabled" SET NOT NULL, ALTER "roles" TYPE TEXT, ALTER "roles" SET NOT NULL',
        ];

        yield 'ALTER TABLE CHANGE without rename' => [
            'ALTER TABLE test CHANGE status status VARCHAR(50) NOT NULL',
            'ALTER TABLE test ALTER "status" TYPE VARCHAR(50), ALTER "status" SET NOT NULL',
        ];
    }

    public function testDropTableThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot use addSql() with DROP TABLE');

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

        $migration = $this->createMigration($connection);
        $migration->testAddSql('DROP TABLE test');
    }
}
