<?php

declare(strict_types=1);

namespace Basis\Picodata;

use Basis\Picodata\Driver\Pgsql;
use Basis\Picodata\Driver\Pool;
use Basis\Picodata\Exception\NotFoundException;
use Basis\Picodata\Map\Mapper;
use Basis\Picodata\Map\Resolver;
use Basis\Picodata\Schema\SchemaManager;

/**
 * Picodata client: raw SQL, schema management and entity operations.
 *
 * $target argument of entity methods is either a class-string of an entity
 * class or a plain table name ('user', 'auth_user' — Picodata table names are
 * flat cluster-wide; it has no schemas). For table names the schema is
 * introspected from _pico_table and a typed class is generated when missing.
 */
final class Picodata implements Driver
{
    private readonly Mapper $mapper;
    private readonly Repository $repository;
    private readonly SchemaManager $schema;
    private ?Changes $changes = null;
    private int $transactionLevel = 0;

    public function __construct(private readonly Driver $driver, ?Mapper $mapper = null)
    {
        $this->mapper = $mapper ?? new Mapper();
        $this->schema = new SchemaManager($this, $this->mapper);
        $this->repository = new Repository(
            $this,
            $this->mapper,
            fn (string $table): mixed => $this->schema->load($table),
            fn (): ?Changes => $this->changes,
        );
    }

    /**
     * Connect through the PostgreSQL wire protocol (ext-pgsql).
     *
     * @param ?Resolver $resolver class <-> table naming rule (default: an empty
     *                            PrefixedResolver, i.e. snake_case short class name)
     */
    public static function connect(string $dsn, ?Mapper $mapper = null, ?Resolver $resolver = null): self
    {
        return new self(new Pgsql($dsn), $mapper ?? new Mapper($resolver));
    }

    /**
     * Connect to a pool of hosts: one DSN per entry, picked at random on the
     * first statement, failing over to the next host on a lost connection
     * (see Driver\Pool). Typical setup is one comma-separated PICODATA_DSN
     * env var:
     *
     *   Picodata::connectPool(explode(',', getenv('PICODATA_DSN')));
     */
    public static function connectPool(array $dsns, ?Mapper $mapper = null, ?Resolver $resolver = null): self
    {
        return new self(new Pool($dsns), $mapper ?? new Mapper($resolver));
    }

    /**
     * Change journal (sharding.php-style guaranteed registration): subscribe
     * listeners, drain with get()/ack(). Until register() is called, writes
     * carry zero journaling overhead.
     */
    public function changes(): Changes
    {
        return $this->changes ??= new Changes(
            $this->driver,
            fn (): bool => $this->transactionLevel > 0,
            $this->mapper,
        );
    }

    public function driver(): Driver
    {
        return $this->driver;
    }

    /** Forget identity-mapped instances; next fetch hydrates fresh objects. */
    public function clearIdentity(): void
    {
        $this->repository->clearIdentity();
    }

    public function schema(): SchemaManager
    {
        return $this->schema;
    }

    public function mapper(): Mapper
    {
        return $this->mapper;
    }

    public function statement(string $sql, array $params = []): Result
    {
        return $this->driver->statement($sql, $params);
    }

    public function driverName(): string
    {
        return $this->driver->driverName();
    }

    /** Execute SQL and return rows. Raw access — full Picodata SQL is available. */
    public function query(string $sql, array $params = []): Result
    {
        return $this->statement($sql, $params);
    }

    /** Execute SQL and return the number of affected rows. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->statement($sql, $params)->rowCount();
    }

    /**
     * Run $fn against the connection.
     *
     * Picodata's PostgreSQL wire protocol is autocommit-only: it does not honour
     * transaction blocks over pgwire (BEGIN/COMMIT/ROLLBACK parse but give no
     * atomicity, and SAVEPOINT/RELEASE/ROLLBACK TO fail to parse). This mirrors
     * Picodata's own reference driver, picopyn, which rejects `autocommit=False`
     * and implements a "no-op transaction" (picopyn GL-40). $fn is simply
     * invoked; writes commit as they run. Do not rely on rollback here — batch
     * the work or guard it with an idempotent retry instead.
     *
     * @template T
     * @param  callable(Picodata): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        return $fn($this);
    }

    /**
     * Select rows: find(User::class, ['active' => 1]). The target is an entity
     * class, a table name, a Schema\Table, or a class-free Schema\Model.
     *
     * @return list<object>
     */
    public function find(string|object $target, array $where = [], array $options = []): array
    {
        return $this->repository->find($target, $where, $options);
    }

    public function findOne(string|object $target, array $where = [], array $options = []): ?object
    {
        return $this->repository->findOne($target, $where, $options);
    }

    /** @throws NotFoundException */
    public function findOrFail(string|object $target, array $where = [], array $options = []): object
    {
        return $this->repository->findOrFail($target, $where, $options);
    }

    /** Row by primary key: get(User::class, 42) or get(User::class, $user). */
    public function get(string|object $target, array|int|string|object $key): ?object
    {
        return $this->repository->get($target, $key);
    }

    /**
     * Find a row by $attributes or insert it (attributes + $values) and return it.
     *
     * @param  array<string, mixed> $attributes lookup columns
     * @param  array<string, mixed> $values     extra columns for the insert
     */
    public function findOrCreate(string|object $target, array $attributes, array $values = []): object
    {
        return $this->repository->findOrCreate($target, $attributes, $values);
    }

    public function insert(string|object $target, ?array $values = null): object
    {
        return $this->repository->insert($target, $values);
    }

    /**
     * Update by primary key: update(User::class, 42, ['name' => 'nekufa']) or,
     * with the entity itself as target, update($user, ['locked' => time()]).
     */
    public function update(string|object $target, array|int|string|object $key, ?array $changes = null): int
    {
        return $this->repository->update($target, $key, $changes);
    }

    public function delete(string|object $target, array|int|string|object $key): int
    {
        return $this->repository->delete($target, $key);
    }

    /** Insert or update the entity by its primary key. */
    public function save(object $entity): object
    {
        return $this->repository->save($entity);
    }
}
