<?php

namespace Chap\Database;

use PDO;
use PDOException;

/**
 * Database Connection Manager
 */
class Connection
{
    private static ?PDO $instance = null;
    private PDO $pdo;

    public function __construct()
    {
        $this->connect();
    }

    /**
     * Connect to database
     */
    private function connect(): void
    {
        if (self::$instance !== null) {
            $this->pdo = self::$instance;
            return;
        }

        $host = config('database.host', 'localhost');
        $port = config('database.port', 3306);
        $database = config('database.database', 'chap');
        $username = config('database.username', 'chap');
        $password = config('database.password', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$instance = $this->pdo;
        } catch (PDOException $e) {
            throw new PDOException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Get PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a query with prepared statements
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Execute an insert and return last insert ID
     */
    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));
        
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Execute an update
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $stmt = $this->query($sql, array_merge(array_values($data), $whereParams));
        
        return $stmt->rowCount();
    }

    /**
     * Execute a delete
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $params);
        
        return $stmt->rowCount();
    }

    /**
     * Fetch single row
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Check if table exists
     */
    public function tableExists(string $table): bool
    {
        $result = $this->fetch(
            "SELECT COUNT(*) as count FROM information_schema.tables 
             WHERE table_schema = ? AND table_name = ?",
            [config('database.database'), $table]
        );
        return $result && $result['count'] > 0;
    }
}
