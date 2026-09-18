<?php

declare(strict_types=1);

namespace Basis\Picodata\Driver;

use Basis\Picodata\Driver;
use Basis\Picodata\Exception\ExecutionException;
use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Quoter;
use Basis\Picodata\Result;

/**
 * ext-pgsql backed driver; connects lazily on first statement.
 */
final class Pgsql implements Driver
{
    /** @var \PgSql\Connection|false|null Lazily opened connection. */
    private \PgSql\Connection | false | null $connection = null;

    /**
     * @param string $dsn Connection string for pg_connect().
     */
    public function __construct(
        private readonly string $dsn,
    ) {
    }

    public function driverName(): string
    {
        return 'pgsql';
    }

    public function statement(string $sql, array $params = []): Result
    {
        $connection = $this->connect();
        [$sql, $ordered] = Quoter::positional($sql, $params);

        $result = $ordered === []
            ? pg_query($connection, $sql)
            : pg_query_params($connection, $sql, $ordered);

        if ($result === false) {
            throw new ExecutionException(
                sprintf('Query failed: %s; SQL: %s', pg_last_error($connection), $sql),
            );
        }

        $rows = pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : [];
        $affected = pg_affected_rows($result);
        pg_free_result($result);

        return new Result($rows, $affected);
    }

    /**
     * Run $fn inside BEGIN/COMMIT, rolling back and rethrowing on error.
     */
    public function transaction(callable $fn): mixed
    {
        $this->statement('BEGIN');
        try {
            $ret = $fn($this);
            $this->statement('COMMIT');

            return $ret;
        } catch (\Throwable $e) {
            try {
                $this->statement('ROLLBACK');
            } catch (\Throwable) {
                // Keep the original failure.
            }

            throw $e;
        }
    }

    /**
     * @throws InvalidException When ext-pgsql is missing.
     * @throws ExecutionException When the connection fails.
     */
    private function connect(): \PgSql\Connection
    {
        if ($this->connection instanceof \PgSql\Connection) {
            return $this->connection;
        }

        if (!extension_loaded('pgsql')) {
            throw new InvalidException('PHP extension "pgsql" is required by the pgsql driver');
        }

        $connection = @pg_connect($this->dsn);
        if ($connection === false) {
            throw new ExecutionException('Unable to connect to pgsql: ' . $this->dsn);
        }

        return $this->connection = $connection;
    }
}
