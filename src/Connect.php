<?php

namespace App;

class Connect
{
    private static ?Connect $instance = null;
    private \PDO $connection;

    private function __construct()
    {
        $this->connect();
    }

    public static function getInstance(): Connect
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect(): void
    {
        $databaseUrl = parse_url((string) getenv('DATABASE_URL'));
        if ($databaseUrl === false) {
            throw new \Exception("Error reading DATABASE_URL environment variable");
        }

        $username = $databaseUrl['user'] ?? '';
        $password = $databaseUrl['pass'] ?? '';
        $host = $databaseUrl['host'] ?? 'localhost';
        $port = $databaseUrl['port'] ?? 5432;
        $dbName = ltrim($databaseUrl['path'] ?? '', '/') ?: 'mydb';

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        try {
            $this->connection = new \PDO($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public function getConnection(): \PDO
    {
        return $this->connection;
    }
}
