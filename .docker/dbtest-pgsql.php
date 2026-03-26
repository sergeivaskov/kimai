<?php
$DATABASE_HOST = urldecode($argv[1]);
$DATABASE_BASE = urldecode($argv[2]);
$DATABASE_PORT = $argv[3];
$DATABASE_USER = urldecode($argv[4]);
$DATABASE_PASS = urldecode($argv[5]);

echo "Testing DB (PostgreSQL):";

try {
    $pdo = new \PDO("pgsql:host=$DATABASE_HOST;dbname=$DATABASE_BASE;port=$DATABASE_PORT", "$DATABASE_USER", "$DATABASE_PASS", [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
    ]);
    echo "Connected successfully!\n";
    exit(0);
} catch(\Exception $ex) {
    echo $ex->getMessage() . "\n";
    exit(1);
}
