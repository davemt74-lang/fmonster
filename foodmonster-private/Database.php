<?php
declare(strict_types=1);
if (!defined('FOODMONSTER_BOOTSTRAP')) { http_response_code(404); exit; }

final class FMDatabase
{
    public PDO $pdo;
    public string $driver;
    private bool $inTransaction = false;

    public function __construct(array $config)
    {
        $this->driver = (string)($config['driver'] ?? 'mysql');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => false];
        if ($this->driver === 'sqlite') {
            $path = (string)($config['path'] ?? '');
            if ($path === '') { throw new RuntimeException('Database path is not configured.'); }
            $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 10000');
        } elseif ($this->driver === 'mysql') {
            $host = (string)($config['host'] ?? 'localhost');
            $port = (int)($config['port'] ?? 3306);
            $name = (string)($config['name'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $host) || !preg_match('/^[A-Za-z0-9_-]+$/D', $name) || $port < 1 || $port > 65535) { throw new RuntimeException('Database settings are invalid.'); }
            $this->pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", (string)($config['user'] ?? ''), (string)($config['password'] ?? ''), $options + [PDO::ATTR_EMULATE_PREPARES => false]);
            $this->pdo->exec("SET time_zone = '+00:00'");
        } else { throw new RuntimeException('Unsupported database driver.'); }
    }

    private function statement(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : (is_bool($value) ? PDO::PARAM_BOOL : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            $statement->bindValue($i + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }

    public function query(string $sql, array $params = []): array { return $this->statement($sql, $params)->fetchAll(); }
    public function one(string $sql, array $params = []): ?array { $row = $this->statement($sql, $params)->fetch(); return $row === false ? null : $row; }
    public function execute(string $sql, array $params = []): int { return $this->statement($sql, $params)->rowCount(); }
    public function id(): string { return bin2hex(random_bytes(16)); }

    public function transaction(callable $fn): mixed
    {
        if ($this->inTransaction) { return $fn(); }
        if ($this->driver === 'sqlite') { $this->pdo->exec('BEGIN IMMEDIATE'); } else { $this->pdo->beginTransaction(); }
        $this->inTransaction = true;
        try {
            $result = $fn();
            if ($this->driver === 'sqlite') { $this->pdo->exec('COMMIT'); } else { $this->pdo->commit(); }
            $this->inTransaction = false;
            return $result;
        } catch (Throwable $error) {
            try { if ($this->driver === 'sqlite') { $this->pdo->exec('ROLLBACK'); } elseif ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } } catch (Throwable) {}
            $this->inTransaction = false;
            throw $error;
        }
    }

    public function installed(): bool
    {
        try { return $this->one('SELECT value_text FROM app_meta WHERE key_name = ?', ['installed']) !== null; }
        catch (PDOException) { return false; }
    }

    public function migrate(string $schemaDirectory): void
    {
        foreach (['auth', 'commerce'] as $part) {
            $path = $schemaDirectory . '/' . $part . '.' . $this->driver . '.sql';
            if (!is_file($path)) { throw new RuntimeException('Required application schema is missing.'); }
            $sql = file_get_contents($path);
            if ($sql === false) { throw new RuntimeException('Could not read application schema.'); }
            $this->pdo->exec($sql);
        }
    }
}
