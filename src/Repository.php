<?php

declare(strict_types=1);

namespace Basis\Picodata;

use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Exception\NotFoundException;
use Basis\Picodata\Map\Mapper;
use Basis\Picodata\Schema\Table;
use Basis\Picodata\Map\ClassFactory;
use Basis\Picodata\Schema\Model;

/**
 * Row-level CRUD for a single driver/mapper pair.
 *
 * Targets are either entity class names / objects or raw table names; when a
 * table name is unknown to the mapper an optional resolver (SchemaManager::load
 * wrapper) may lazily load and register its schema.
 */
final class Repository
{
    /** Cached live instances keyed 'table::pk1|pk2'; same row => same object. */
    private array $identity = [];

    /**
     * @param ?\Closure $resolver fn(string $table): Table — lazy schema loader, may register ClassFactory
     */
    public function __construct(
        private Driver $driver,
        private Mapper $mapper,
        private ?\Closure $resolver = null,
        private ?\Closure $changes = null,
    ) {
    }

    /**
     * Fetch rows as objects. Default columns: all introspected table columns.
     * Extra select options (order/limit/offset/columns) pass through to Sql::select.
     */
    public function find(string|object $target, array $where = [], array $options = []): array
    {
        $target = $this->target($target);
        $table = $this->table($target);
        $columns = $options['columns'] ?? array_keys($table->columns) ?: ['*'];
        unset($options['columns']);

        $result = $this->run(Sql::select($table->name, $columns, $where, $options));

        $class = match (true) {
            is_object($target) => $target::class,
            class_exists($target) => $target,
            default => $this->mapper->resolveClass($table->name),
        };

        $pk = $this->primaryKey($target);
        $instances = [];

        foreach ($result->all() as $row) {
            $key = $this->identityKey($table->name, $pk, $row);

            if ($key !== null && isset($this->identity[$key])) {
                $this->mapper->fill($this->identity[$key], $row);
                $instances[] = $this->identity[$key];
                continue;
            }

            $instance = $this->mapper->hydrate($class, $row);
            if ($key !== null) {
                $this->identity[$key] = $instance;
            }
            $instances[] = $instance;
        }

        return $instances;
    }

    /** First match or null. */
    public function findOne(string|object $target, array $where = [], array $options = []): ?object
    {
        $options['limit'] = 1;
        $rows = $this->find($target, $where, $options);

        return $rows[0] ?? null;
    }

    /** First match or NotFoundException. */
    public function findOrFail(string|object $target, array $where = [], array $options = []): object
    {
        $found = $this->findOne($target, $where, $options);
        if ($found === null) {
            throw NotFoundException::forTable($this->table($target)->name, $where);
        }

        return $found;
    }

    /** Row by primary key (scalar / map / entity), or null. */
    public function get(string|object $target, array|int|string|object $key): ?object
    {
        $target = $this->target($target);
        return $this->findOne($target, $this->keyWhere($target, $key));
    }

    /** Find by $attributes, otherwise INSERT (attributes + $values) and re-find. */
    public function findOrCreate(string|object $target, array $attributes, array $values = []): object
    {
        $target = $this->target($target);
        $found = $this->findOne($target, $attributes);
        if ($found !== null) {
            return $found;
        }

        $table = $this->table($target);
        $onConflict = $this->mapper->primaryKey($target) !== [] ? 'ON CONFLICT DO NOTHING' : null;

        return $this->guarded($table->name, function (bool $journaling) use ($table, $target, $attributes, $values, $onConflict): array {
            $affected = $this->run(Sql::insert($table->name, $attributes + $values, $onConflict))->rowCount();

            $found = $this->findOne($target, $attributes);
            if ($found === null) {
                throw NotFoundException::forTable($table->name, $attributes);
            }

            return [
                $found,
                $journaling && $affected > 0
                    ? ['action' => 'insert', 'data' => $this->mapper->row($found)]
                    : null,
            ];
        });
    }

    /** INSERT a row (from entity or column map) and return the reloaded object. */
    public function insert(string|object $target, ?array $values = null): object
    {
        $target = $this->target($target);
        $table = $this->table($target);

        if (is_object($target)) {
            $values = $this->mapper->row($target);
        }
        if ($values === null || $values === []) {
            throw new InvalidException('INSERT requires column values');
        }
        foreach (array_keys($values) as $column) {
            if (!is_string($column)) {
                throw new InvalidException('INSERT values must be a column => value map');
            }
        }

        $pk = $this->primaryKey($target);

        return $this->guarded($table->name, function () use ($table, $target, $values, $pk): array {
            $this->run(Sql::insert($table->name, $values));

            $key = array_intersect_key($values, array_flip($pk));

            if ($key !== [] && !in_array(null, $key, true)) {
                $found = $this->findOne($target, $key);
            } elseif (is_object($target)) {
                $found = $this->findOne($target, $values);
            } else {
                throw new InvalidException('Table has no primary key: ' . $table->name);
            }

            if ($found === null) {
                throw NotFoundException::forTable($table->name, $values);
            }

            return [$found, ['action' => 'insert', 'data' => $this->mapper->row($found)]];
        });
    }

    /**
     * UPDATE by primary key; mutates $target properties on success.
     *
     * Forms: update(User::class, 42, [...]), update($user, [...]) (changes as
     * second arg, PK taken from the entity), update(User::class, $user, [...]).
     */
    public function update(string|object $target, array|int|string|object $key, ?array $changes = null): int
    {
        $target = $this->target($target);
        if ($changes === null) {
            if (!is_object($target) || !is_array($key)) {
                throw new InvalidException('update($entity, $changes) requires an entity target and a changes map');
            }
            [$changes, $key] = [$key, $target];
        }

        $table = $this->table($target);
        $where = $this->keyWhere($target, $key);

        $rowCount = $this->guarded($table->name, function (bool $journaling) use ($table, $target, $changes, $where): array {
            $rowCount = $this->run(Sql::update($table->name, $changes, $where))->rowCount();

            $change = null;
            if ($journaling && $rowCount > 0) {
                $after = $this->findOne($target, $where);
                $change = ['action' => 'update', 'data' => $after !== null ? $this->mapper->row($after) : $changes];
            }

            return [$rowCount, $change];
        });

        $entity = is_object($target) ? $target : (is_object($key) ? $key : null);
        if ($entity !== null && $rowCount > 0) {
            $map = $this->mapper->columns($entity);
            foreach ($changes as $column => $value) {
                if (isset($map[$column])) {
                    $entity->{$map[$column]} = $value;
                }
            }

            if (($k = $this->identityKey($table->name, $this->primaryKey($target), $where)) !== null) {
                $this->identity[$k] = $entity;
            }
        }

        return $rowCount;
    }

    /** DELETE by primary key, entity, or column map. Returns affected rows. */
    public function delete(string|object $target, array|int|string|object $key): int
    {
        $target = $this->target($target);
        $table = $this->table($target);
        $where = $this->keyWhere($target, $key);

        $result = $this->guarded($table->name, function (bool $journaling) use ($table, $target, $where): array {
            $before = $journaling ? $this->findOne($target, $where) : null;
            $rowCount = $this->run(Sql::delete($table->name, $where))->rowCount();

            return [
                $rowCount,
                $rowCount > 0
                    ? ['action' => 'delete', 'data' => $before !== null ? $this->mapper->row($before) : $where]
                    : null,
            ];
        });

        if ($result > 0 && ($k = $this->identityKey($table->name, $this->primaryKey($target), $where)) !== null) {
            unset($this->identity[$k]);
        }

        return $result;
    }

    /**
     * Persist an entity: UPDATE by primary key when the row already exists,
     * otherwise INSERT. A new entity that already carries a PK value is inserted
     * (the UPDATE affecting zero rows signals the row is absent), so callers can
     * hand save() a fresh object with an assigned key and still get it stored.
     */
    public function save(object $entity): object
    {
        $pk = $this->mapper->pkValues($entity);

        if ($pk !== null) {
            $changes = array_diff_key($this->mapper->row($entity), array_fill_keys(array_keys($pk), true));

            if ($changes !== [] ? $this->update($entity, $entity, $changes) > 0 : $this->get($entity, $entity) !== null) {
                return $entity;
            }
        }

        return $this->insert($entity);
    }

    /**
     * Normalize a CRUD target: a class-free Model or a bare Table becomes its
     * registered table name (registered on first use), so dynamic definitions
     * flow through the exact same name-based path as any other table. Entity
     * objects and class/table-name strings pass through untouched.
     */
    private function target(string|object $target): string|object
    {
        if ($target instanceof Model) {
            $target = $target->toTable();
        }
        if ($target instanceof Table) {
            if (ClassFactory::tableFor($target->name) === null) {
                ClassFactory::register($target->name, $target);
            }

            return $target->name;
        }

        return $target;
    }

    /** Resolve the schema table, retrying once through the resolver for unknown names. */
    private function table(string|object $target): Table
    {
        try {
            return $this->mapper->table($target);
        } catch (InvalidException $e) {
            if (is_object($target) || $this->resolver === null) {
                throw $e;
            }
            ($this->resolver)($target);

            return $this->mapper->table($target);
        }
    }

    /** Introspected primary key; falls back to an entity's own pk columns. */
    private function primaryKey(string|object $target): array
    {
        $pk = $this->mapper->primaryKey($target);
        if ($pk === [] && is_object($target)) {
            $pk = array_keys($this->mapper->pkValues($target) ?? []);
        }

        return $pk;
    }

    /** Normalize a key (array / scalar / entity pk) into a WHERE map. */
    private function keyWhere(string|object $target, array|int|string|object $key): array
    {
        if (is_object($key)) {
            $pk = $this->mapper->pkValues($key);
            if ($pk === null || $pk === []) {
                throw new InvalidException('Entity key carries no primary key values');
            }

            return $pk;
        }

        if (is_array($key)) {
            return $key;
        }

        if (is_object($target)) {
            $pk = $this->mapper->pkValues($target);
            if ($pk !== null && $pk !== []) {
                return $pk;
            }
        }

        $pk = $this->primaryKey($target);
        if ($pk === []) {
            throw new InvalidException('No primary key to address row');
        }

        return [$pk[0] => $key];
    }

    /** @return Result */
    private function run(Query $query): Result
    {
        return $this->driver->statement($query->sql, $query->params);
    }

    /**
     * Run $work(true) journal-atomically when journaling applies to $table,
     * otherwise $work(false) plainly. $work returns [result, ?change].
     */
    private function guarded(string $table, \Closure $work): mixed
    {
        $changes = $this->changes === null ? null : ($this->changes)();

        if ($changes === null || !$changes->applies($table)) {
            return $work(false)[0];
        }

        return $changes->guard($table, $work);
    }


    /** Forget all identity-mapped instances (fresh objects on next fetch). */
    public function clearIdentity(): void
    {
        $this->identity = [];
    }

    /** Identity key when every pk column is present and non-null in $row. */
    private function identityKey(string $table, array $pk, array $row): ?string
    {
        $parts = [];
        foreach ($pk as $column) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                return null;
            }
            $parts[] = is_scalar($row[$column]) ? (string) $row[$column] : serialize($row[$column]);
        }

        return $parts === [] ? null : $table . '::' . implode('|', $parts);
    }
}
