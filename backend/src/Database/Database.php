<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Env;
use mysqli;
use mysqli_stmt;
use RuntimeException;
use Throwable;

/**
 * Singleton database wrapper providing managed MySQLi connections,
 * prepared statement execution, and transaction lifecycle.
 */
class Database
{
    private static ?Database $instance = null;
    private ?mysqli $connection = null;
    private int $transactionDepth = 0;
    /** @var array<int, callable> */
    private array $afterCommitCallbacks = [];
    /** @var array<int, callable> */
    private array $beforeCommitCallbacks = [];

    private function __construct()
    {
    }

    /**
     * Get the singleton Database instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Retrieve the active MySQLi connection, initializing if necessary.
     */
    public function getConnection(): mysqli
    {
        if ($this->connection !== null && $this->transactionDepth > 0) {
            // Never silently reconnect mid-transaction: the open transaction would be lost.
            return $this->connection;
        }

        if ($this->connection !== null && @$this->connection->ping()) {
            return $this->connection;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::int('DB_PORT', 3306);
        $database = Env::get('DB_DATABASE', 'shopco_dev');
        $username = Env::get('DB_USERNAME', 'root');
        $password = Env::get('DB_PASSWORD', '');

        // Report all mysqli errors as exceptions
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $conn = new mysqli($host, $username, $password, $database, $port);
            $conn->set_charset('utf8mb4');
            // Align NOW() with the store clock (APP_TIMEZONE, which the API and scripts also give PHP)
            // so token/session/order expiry comparisons agree, whatever php.ini's default zone is.
            try {
                $zone = new \DateTimeZone((string) (Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos'));
            } catch (\Exception $e) {
                $zone = new \DateTimeZone('Africa/Lagos');
            }
            $offset = (new \DateTimeImmutable('now', $zone))->format('P');
            $conn->query("SET time_zone = '" . $conn->real_escape_string($offset) . "'");
            $this->connection = $conn;
            return $this->connection;
        } catch (Throwable $e) {
            // Log full exception internally, but never leak credentials to the caller
            error_log(sprintf('[DB Connection Failure] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            throw new RuntimeException('Database connection failed. Please ensure MySQL is running in XAMPP.', 500);
        }
    }

    /**
     * Check database connectivity for the health check endpoint.
     *
     * @return array{connected: bool, database: string, latency_ms?: float, error?: string}
     */
    public function ping(): array
    {
        $start = microtime(true);
        try {
            $conn = $this->getConnection();
            $latency = round((microtime(true) - $start) * 1000, 2);
            $dbName = Env::get('DB_DATABASE', 'shopco_dev');

            return [
                'connected' => true,
                'database' => $dbName,
                'latency_ms' => $latency,
            ];
        } catch (Throwable $e) {
            return [
                'connected' => false,
                'database' => Env::get('DB_DATABASE', 'shopco_dev'),
                'error' => 'Database unreachable (check XAMPP MySQL service)',
            ];
        }
    }

    /**
     * Execute a prepared statement with parameterized bindings.
     *
     * Example:
     *   $db->execute("UPDATE products SET stock = ? WHERE id = ?", "is", [15, "prod-123"]);
     *
     * @param string $sql SQL query with ? placeholders
     * @param string $types Type string e.g. "s" (string), "i" (int), "d" (double), "b" (blob)
     * @param array<int, mixed> $params Array of parameters matching types
     */
    public function execute(string $sql, string $types = '', array $params = []): mysqli_stmt
    {
        $conn = $this->getConnection();
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log(sprintf('[DB Prepare Failed] Query: %s Error: %s', $sql, $conn->error));
            throw new RuntimeException('Failed to prepare database statement.', 500);
        }

        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        return $stmt;
    }

    /**
     * Execute an UPDATE/DELETE/INSERT statement and return number of affected rows.
     */
    public function executeUpdate(string $sql, string $types = '', array $params = []): int
    {
        $stmt = $this->execute($sql, $types, $params);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /**
     * Fetch all rows from a parameterized query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->execute($sql, $types, $params);
        $result = $stmt->get_result();

        if ($result === false) {
            $stmt->close();
            return [];
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Fetch a single row from a parameterized query.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = $this->execute($sql, $types, $params);
        $result = $stmt->get_result();

        if ($result === false) {
            $stmt->close();
            return null;
        }

        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Insert a record and return the generated auto-increment ID.
     */
    public function insert(string $sql, string $types = '', array $params = []): int|string
    {
        $stmt = $this->execute($sql, $types, $params);
        $insertId = $this->getConnection()->insert_id;
        $stmt->close();
        return $insertId;
    }

    /**
     * Return the auto-increment ID generated by the most recent INSERT on this connection.
     */
    public function lastInsertId(): int
    {
        return (int) $this->getConnection()->insert_id;
    }

    /**
     * Roll back a transaction left open when the request ended inside one (an answer sent from
     * inside transaction() ends the script without unwinding it). Called first at shutdown, so
     * the work done after the response (emails, cache refresh) never runs inside a transaction
     * that is about to be thrown away, and no row stays locked while it runs.
     */
    public static function rollBackIfLeftOpen(): void
    {
        $db = self::$instance;
        if ($db === null || $db->connection === null || $db->transactionDepth === 0) {
            return;
        }
        $db->transactionDepth = 0;
        $db->afterCommitCallbacks = [];
        $db->beforeCommitCallbacks = [];
        try {
            $db->connection->rollback();
        } catch (Throwable $e) {
            error_log('[Transaction left open] ' . $e->getMessage());
        }
    }

    /**
     * Whether a transaction is currently open on this connection.
     */
    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /**
     * Run a callback once the outermost transaction commits (immediately if none is open).
     * Use for side effects that must not happen if the data change is rolled back,
     * such as sending emails. Callbacks are discarded on rollback.
     */
    public function afterCommit(callable $callback): void
    {
        if ($this->transactionDepth === 0) {
            $this->runSafely($callback);
            return;
        }
        $this->afterCommitCallbacks[] = $callback;
    }

    /**
     * Run a callback inside the outermost transaction, after its other work and just before it
     * commits (immediately if none is open). Its writes commit or roll back with the transaction,
     * and an exception from it rolls the whole transaction back. Use for rows that must exist
     * exactly when the change does, such as an outbox email for a confirmed payment.
     */
    public function beforeCommit(callable $callback): void
    {
        if ($this->transactionDepth === 0) {
            $callback();
            return;
        }
        $this->beforeCommitCallbacks[] = $callback;
    }

    /**
     * Execute a callback inside an atomic MySQL transaction with automatic rollback on failure.
     * Nested calls join the outer transaction using a savepoint, so a service that opens a
     * transaction can safely call another service that does the same.
     *
     * @template T
     * @param callable(Database): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $conn = $this->getConnection();

        if ($this->transactionDepth > 0) {
            $savepoint = 'sp_' . $this->transactionDepth;
            $conn->query("SAVEPOINT {$savepoint}");
            $this->transactionDepth++;
            try {
                $result = $callback($this);
                $this->transactionDepth--;
                $conn->query("RELEASE SAVEPOINT {$savepoint}");
                return $result;
            } catch (Throwable $e) {
                $this->transactionDepth--;
                $conn->query("ROLLBACK TO SAVEPOINT {$savepoint}");
                throw $e;
            }
        }

        $conn->begin_transaction();
        $this->transactionDepth = 1;
        $this->afterCommitCallbacks = [];
        $this->beforeCommitCallbacks = [];

        try {
            $result = $callback($this);
            while ($this->beforeCommitCallbacks !== []) {
                $before = array_shift($this->beforeCommitCallbacks);
                $before();
            }
            $conn->commit();
            $this->transactionDepth = 0;
        } catch (Throwable $e) {
            $this->transactionDepth = 0;
            $this->afterCommitCallbacks = [];
            $this->beforeCommitCallbacks = [];
            $conn->rollback();
            error_log(sprintf('[Transaction Rollback] Error: %s', $e->getMessage()));
            throw $e;
        }

        $callbacks = $this->afterCommitCallbacks;
        $this->afterCommitCallbacks = [];
        foreach ($callbacks as $cb) {
            $this->runSafely($cb);
        }

        return $result;
    }

    private function runSafely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            error_log('[After-Commit Callback Failure] ' . $e->getMessage());
        }
    }
}
