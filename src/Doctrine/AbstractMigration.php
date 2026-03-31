<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Doctrine;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration as BaseAbstractMigration;

/**
 * Base class for all Doctrine migrations.
 *
 * @codeCoverageIgnore
 */
abstract class AbstractMigration extends BaseAbstractMigration
{
    /**
     * Indexes extracted from CREATE TABLE statements that need to be created separately.
     * @var string[]
     */
    private array $pendingIndexes = [];

    /**
     * @see https://github.com/doctrine/migrations/issues/1104
     */
    public function isTransactional(): bool
    {
        return false;
    }

    /**
     * @throws Exception
     */
    public function preUp(Schema $schema): void
    {
        $this->abortIfPlatformNotSupported();
    }

    /**
     * @throws Exception
     */
    public function preDown(Schema $schema): void
    {
        $this->abortIfPlatformNotSupported();
    }

    /**
     * Abort the migration is the current platform is not supported.
     *
     * @throws Exception
     */
    private function abortIfPlatformNotSupported(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!($platform instanceof MySQLPlatform) && !($platform instanceof PostgreSQLPlatform)) {
            $this->abortIf(true, 'Unsupported database platform: ' . \get_class($platform));
        }
    }

    protected function preventEmptyMigrationWarning(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $comment = $platform instanceof PostgreSQLPlatform 
            ? 'SELECT 1 /* prevent empty warning - no SQL to execute */' 
            : '#prevent empty warning - no SQL to execute';
        
        $this->addSql($comment);
    }

    /**
     * I don't know how often I accidentally dropped database tables,
     * because a generated "left-over" migration was executed.
     *
     * @param mixed[] $params
     * @param mixed[] $types
     */
    protected function addSql(string $sql, array $params = [], array $types = []): void
    {
        if (str_starts_with($sql, 'DROP TABLE ')) {
            throw new \InvalidArgumentException('Cannot use addSql() with DROP TABLE');
        }

        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $sql = $this->translateToPostgreSQL($sql);
        }

        parent::addSql($sql, $params, $types);

        // Add any pending indexes that were extracted from CREATE TABLE
        foreach ($this->pendingIndexes as $indexSql) {
            parent::addSql($indexSql);
        }
        $this->pendingIndexes = [];
    }

    /**
     * Translates MySQL-specific SQL syntax to PostgreSQL-compatible syntax.
     * 
     * Handles deterministic transformations:
     * 1. TINYINT(1) → BOOLEAN
     * 2. LONGTEXT/TINYTEXT/MEDIUMTEXT → TEXT
     * 3. ENGINE=InnoDB (and variants) → removed
     * 4. AUTO_INCREMENT → removed (SERIAL already handles this)
     * 5. COMMENT '(DC2Type:...)' → removed (Doctrine stores mapping in metadata)
     * 6. INDEX/UNIQUE INDEX inside CREATE TABLE → extracted and created separately
     * 7. Quote PostgreSQL reserved keywords (user, group, order, etc.)
     */
    private function translateToPostgreSQL(string $sql): string
    {
        // 0. Quote PostgreSQL reserved keywords when used as column names
        $reservedKeywords = ['user', 'group', 'order', 'table', 'column', 'index', 'check', 'grant'];
        foreach ($reservedKeywords as $keyword) {
            // Match keyword as column name in CREATE/ALTER TABLE: word boundary, keyword, space/comma
            $sql = preg_replace(
                '/\b(' . $keyword . ')\s+(INT|VARCHAR|TEXT|BOOLEAN|SERIAL|NUMERIC|TIMESTAMP|DOUBLE|CHARACTER)/i',
                '"$1" $2',
                $sql
            );
            // Match keyword in column references (e.g., FOREIGN KEY (user))
            // But not inside string literals
            $sql = preg_replace(
                '/\((' . $keyword . ')\)(?![^(]*\')/i',
                '("$1")',
                $sql
            );
        }
        // 1. Convert TINYINT(1) to BOOLEAN (with word boundary before TINYINT)
        $sql = preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i', 'BOOLEAN', $sql);
        // Convert DEFAULT 0/1 to DEFAULT FALSE/TRUE for BOOLEAN columns
        $sql = preg_replace('/\bBOOLEAN\s+DEFAULT\s+0\b/i', 'BOOLEAN DEFAULT FALSE', $sql);
        $sql = preg_replace('/\bBOOLEAN\s+DEFAULT\s+1\b/i', 'BOOLEAN DEFAULT TRUE', $sql);

        // 2. Convert LONGTEXT, TINYTEXT, MEDIUMTEXT to TEXT  
        $sql = preg_replace('/\b(LONG|TINY|MEDIUM)TEXT\b/i', 'TEXT', $sql);

        // 2.5. Convert DATETIME to TIMESTAMP (PostgreSQL doesn't have DATETIME)
        $sql = preg_replace('/\bDATETIME\b/i', 'TIMESTAMP', $sql);

        // 2.6. Convert MySQL backticks to PostgreSQL double quotes for identifiers
        $sql = str_replace('`', '"', $sql);

        // 3. Remove ENGINE=InnoDB (with optional spaces and case variations)
        $sql = preg_replace('/\s+ENGINE\s*=\s*InnoDB\b/i', '', $sql);

        // 4. Remove AUTO_INCREMENT keyword
        $sql = preg_replace('/\bAUTO_INCREMENT\b/i', '', $sql);

        // 5. Remove Doctrine type comments (DC2Type:*)
        $sql = preg_replace("/\s*COMMENT\s+'\\(DC2Type:[^']+\\)'/i", '', $sql);

        // 5.5. Remove MySQL-specific charset/collation (must be before INDEX extraction)
        $sql = preg_replace('/\s+DEFAULT\s+CHARACTER\s+SET\s+\w+(\s+COLLATE\s+`?[\w_]+`?)?/i', '', $sql);

        // 5.6. Fix DROP INDEX syntax (PostgreSQL doesn't use ON table_name)
        $sql = preg_replace('/DROP INDEX\s+(\w+)\s+ON\s+\w+/i', 'DROP INDEX $1', $sql);

        // 5.7. Fix DROP FOREIGN KEY to DROP CONSTRAINT (PostgreSQL syntax)
        $sql = preg_replace('/DROP FOREIGN KEY\s+(\w+)/i', 'DROP CONSTRAINT $1', $sql);

        // 5.8. Remove AFTER clause from ADD COLUMN (PostgreSQL doesn't support column positioning)
        $sql = preg_replace('/\s+AFTER\s+\w+/i', '', $sql);

        // 5.9. Fix string literals: PostgreSQL uses single quotes for literals, double quotes for identifiers
        // Convert WHERE name = "value" to WHERE name = 'value'
        $sql = preg_replace_callback(
            '/(WHERE|AND|OR|=)\s+"([^"]+)"/i',
            function ($m) {
                return $m[1] . " '" . $m[2] . "'";
            },
            $sql
        );

        // 5.7. Convert DELETE alias FROM ... to PostgreSQL syntax
        // MySQL: DELETE alias FROM table alias WHERE ...
        // PostgreSQL: DELETE FROM table alias WHERE ...
        $sql = preg_replace('/^DELETE\s+(\w+)\s+FROM/i', 'DELETE FROM', $sql);

        // 5.8. Convert UPDATE ... JOIN to PostgreSQL syntax
        if (preg_match('/^UPDATE\s+/i', $sql) && preg_match('/\s+(LEFT\s+JOIN|INNER\s+JOIN)/i', $sql)) {
            $sql = $this->translateUpdateJoin($sql);
        }

        // 5.9. Convert ALTER TABLE ... MODIFY to PostgreSQL syntax
        // MySQL: ALTER TABLE t MODIFY col TYPE [NULL|NOT NULL] [DEFAULT value]
        // PostgreSQL: ALTER TABLE t ALTER COLUMN col TYPE ..., ALTER COLUMN col DROP/SET NOT NULL, etc.
        if (preg_match('/ALTER TABLE\s+\w+\s+MODIFY\s+/i', $sql)) {
            $sql = $this->translateAlterTableModify($sql);
        }

        // 5.10. Convert ALTER TABLE ... CHANGE to PostgreSQL syntax
        if (preg_match('/^ALTER TABLE\s+(\w+)\s+/i', $sql) && stripos($sql, 'CHANGE') !== false) {
            $sql = $this->translateAlterTableChange($sql);
        }

        // 6. Handle INDEX/UNIQUE INDEX in CREATE TABLE (PostgreSQL doesn't support this syntax)
        if (preg_match('/^CREATE TABLE\s+(\w+)\s*\((.*)\)\s*/is', $sql, $matches)) {
            $tableName = $matches[1];
            $tableBody = $matches[2];

            // Extract all INDEX and UNIQUE INDEX definitions
            $tableBody = preg_replace_callback(
                '/,\s*(UNIQUE\s+)?INDEX\s+(\w+)\s*\(([^)]+)\)/i',
                function ($indexMatches) use ($tableName) {
                    $isUnique = !empty($indexMatches[1]);
                    $indexName = $indexMatches[2];
                    $columns = $indexMatches[3];
                    
                    $indexType = $isUnique ? 'UNIQUE INDEX' : 'INDEX';
                    $this->pendingIndexes[] = "CREATE $indexType $indexName ON $tableName ($columns)";
                    
                    return '';
                },
                $tableBody
            );

            // Reconstruct CREATE TABLE without inline indexes
            $sql = "CREATE TABLE $tableName ($tableBody)";
        }

        return $sql;
    }

    /**
     * Translates MySQL ALTER TABLE ... CHANGE syntax to PostgreSQL.
     * 
     * MySQL CHANGE syntax:
     * - CHANGE old_col new_col TYPE options (rename + change type)
     * - CHANGE col col TYPE options (only change type, no rename)
     * 
     * PostgreSQL needs separate ALTER statements for each operation
     */
    private function translateAlterTableChange(string $sql): string
    {
        if (!preg_match('/^ALTER TABLE\s+(\w+)\s+(.*)/is', $sql, $matches)) {
            return $sql;
        }

        $tableName = $matches[1];
        $clausesRaw = $matches[2];
        $pendingAlters = [];

        // First pass: Handle CHANGE clauses
        // Captures: CHANGE old_col new_col TYPE [constraints]
        // Column names can be: word, `word`, or "word" (already quoted)
        // TYPE can be: VARCHAR(255), DOUBLE PRECISION, INT, etc.
        $clausesRaw = preg_replace_callback(
            '/CHANGE\s+(?:`|")?(\w+)(?:`|")?\s+(?:`|")?(\w+)(?:`|")?\s+((?:DOUBLE\s+PRECISION|CHARACTER\s+VARYING|[A-Z]+)(?:\([^)]+\))?)\s*(.*?)(?=,\s*(?:ADD|DROP|CHANGE|ALTER)|$)/is',
            function ($m) use ($tableName, &$pendingAlters) {
                $oldCol = $m[1];
                $newCol = $m[2];
                $type = trim($m[3]);
                $constraints = trim($m[4]);
                
                // Remove COLLATE (MySQL-specific)
                $constraints = preg_replace('/\s*COLLATE\s+\w+/i', '', $constraints);
                
                if ($oldCol !== $newCol) {
                    // Rename + change type: need to emit separate statements
                    $pendingAlters[] = "ALTER TABLE $tableName RENAME COLUMN \"$oldCol\" TO \"$newCol\"";
                    $col = $newCol;
                } else {
                    $col = $oldCol;
                }
                
                // Build ALTER COLUMN for type change
                $alterClauses = [];
                $alterClauses[] = "ALTER \"$col\" TYPE $type";
                
                // Handle NOT NULL
                if (stripos($constraints, 'NOT NULL') !== false) {
                    $alterClauses[] = "ALTER \"$col\" SET NOT NULL";
                }
                
                // Handle DEFAULT
                if (preg_match('/DEFAULT\s+([^,\s]+(?:\s+[^,]+)?)/i', $constraints, $defMatch)) {
                    $defaultValue = trim($defMatch[1]);
                    if (stripos($defaultValue, 'NULL') === false) {
                        $alterClauses[] = "ALTER \"$col\" SET DEFAULT $defaultValue";
                    }
                }
                
                return implode(', ', $alterClauses);
            },
            $clausesRaw
        );

        $result = "ALTER TABLE $tableName $clausesRaw";
        
        // Add pending rename statements before the main ALTER
        foreach ($pendingAlters as $alterSql) {
            parent::addSql($alterSql);
        }
        
        return $result;
    }

    /**
     * Translates MySQL ALTER TABLE ... MODIFY to PostgreSQL syntax.
     * 
     * MySQL: ALTER TABLE t MODIFY col TYPE [NULL|NOT NULL] [DEFAULT value]
     * PostgreSQL: ALTER TABLE t ALTER COLUMN col TYPE type, ALTER COLUMN col DROP/SET NOT NULL, etc.
     */
    private function translateAlterTableModify(string $sql): string
    {
        // Pattern: ALTER TABLE table MODIFY column type [NULL|NOT NULL] [DEFAULT value]
        if (preg_match(
            '/ALTER TABLE\s+(\w+)\s+MODIFY\s+(\w+)\s+([A-Z]+(?:\s*\([^)]+\))?(?:\s+[A-Z]+)*)\s+(NULL|NOT\s+NULL)?\s*(DEFAULT\s+[^\s,;]+)?/i',
            $sql,
            $m
        )) {
            $table = $m[1];
            $column = $m[2];
            $type = trim($m[3]);
            $nullability = isset($m[4]) ? trim($m[4]) : '';
            $default = isset($m[5]) ? trim($m[5]) : '';

            // Build PostgreSQL commands
            $commands = [];
            $commands[] = "ALTER TABLE $table ALTER COLUMN $column TYPE $type";
            
            if (strcasecmp($nullability, 'NULL') === 0) {
                $commands[] = "ALTER TABLE $table ALTER COLUMN $column DROP NOT NULL";
            } elseif (strcasecmp($nullability, 'NOT NULL') === 0) {
                $commands[] = "ALTER TABLE $table ALTER COLUMN $column SET NOT NULL";
            }
            
            if (!empty($default)) {
                $commands[] = "ALTER TABLE $table ALTER COLUMN $column SET $default";
            }

            // Execute each command separately
            foreach (array_slice($commands, 1) as $cmd) {
                $this->addSql($cmd);
            }

            return $commands[0]; // Return first command for current addSql() call
        }

        return $sql;
    }

    /**
     * Translates MySQL UPDATE ... JOIN syntax to PostgreSQL.
     * 
     * MySQL: UPDATE t1 [alias1] LEFT JOIN t2 [alias2] ON condition SET alias1.col = ... WHERE ...
     * PostgreSQL: UPDATE t1 [alias1] SET col = ... FROM t2 [alias2] WHERE condition AND ...
     */
    private function translateUpdateJoin(string $sql): string
    {
        // Pattern: UPDATE table1 alias1 LEFT JOIN table2 alias2 ON join_condition SET set_clause WHERE where_clause
        if (preg_match(
            '/UPDATE\s+(\w+)\s+(\w+)\s+LEFT\s+JOIN\s+(\w+)\s+(\w+)\s+ON\s+(.+?)\s+SET\s+(.+?)(?:\s+WHERE\s+(.+))?$/is',
            $sql,
            $m
        )) {
            $table1 = $m[1];
            $alias1 = $m[2];
            $table2 = $m[3];
            $alias2 = $m[4];
            $joinCondition = trim($m[5]);
            $setClause = trim($m[6]);
            $whereClause = isset($m[7]) ? trim($m[7]) : '';

            // Remove table alias from SET clause (PostgreSQL doesn't allow alias.column in SET)
            // e.g., "p0.`value` = 'en'" -> "`value` = 'en'"
            $setClause = preg_replace('/' . preg_quote($alias1, '/') . '\./', '', $setClause);

            // Build PostgreSQL syntax
            $pgSql = "UPDATE $table1 $alias1 SET $setClause FROM $table2 $alias2 WHERE $joinCondition";
            if (!empty($whereClause)) {
                $pgSql .= " AND $whereClause";
            }

            return $pgSql;
        }

        return $sql;
    }
}
