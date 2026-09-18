<?php

declare(strict_types=1);

namespace Basis\Picodata\Schema;

use Basis\Picodata\Map\ClassFactory;

/**
 * A class-free table definition — the runtime counterpart of an entity class.
 *
 * It carries everything a mapped class expresses: the physical table name,
 * columns (name, SQL type, nullability, PK/unsigned/array), secondary indexes,
 * engine, distribution and tier — but is described in code, not as a `class`.
 * Use it where the schema is dynamic (built from configuration, a plugin, or a
 * table discovered at runtime) and there is no class to point at.
 *
 * A Model is a first-class target: it works wherever an entity class does —
 * `$db->find($model, ...)`, `$db->get($model, $pk)`, `$db->insert($model, ...)`,
 * and `$db->schema()->create($model)`. Rows hydrate into a typed entity class
 * the library generates once from the definition (see the note in the docs),
 * exactly like it does for a raw table name.
 *
 * Because the name is given explicitly, a Model never consults the naming
 * Resolver: you named the table, so that is the table.
 *
 *     $orders = Model::define('orders')
 *         ->column('id', Column::INTEGER, primary: true)
 *         ->column('username', Column::TEXT)
 *         ->column('total', Column::DOUBLE, nullable: true)
 *         ->index(['username'], unique: true)
 *         ->engine('vinyl')
 *         ->tier('hot');
 */
final class Model
{
    /** @var list<Column> */
    private array $columns = [];

    /** @var string[]|null explicit primary key columns, or null to use flagged/first column */
    private ?array $primary = null;

    /** @var list<Index> */
    private array $indexes = [];

    private string $engine = 'memtx';

    /** null = DISTRIBUTED GLOBALLY; [] = distribute by PK; list = explicit key */
    private ?array $distributed = [];

    private ?string $tier = null;

    private bool $unlogged = false;

    private function __construct(private readonly string $name)
    {
    }

    /** Start a definition for a physical table named $name (used verbatim). */
    public static function define(string $name): self
    {
        return new self($name);
    }

    /**
     * Append a column.
     *
     * @param string $type SQL type: INTEGER, DOUBLE, TEXT, BOOLEAN, DATETIME, UUID, DECIMAL, JSON
     */
    public function column(
        string $name,
        string $type = Column::INTEGER,
        bool $primary = false,
        bool $nullable = false,
        bool $unsigned = false,
        bool $array = false,
        mixed $default = null,
    ): self {
        $this->columns[] = new Column(
            name: $name,
            type: strtoupper($type),
            nullable: $nullable,
            default: $default,
            array: $array,
            unsigned: $unsigned,
            primary: $primary,
        );

        return $this;
    }

    /** Set the primary key columns explicitly (order matters). */
    public function primaryKey(string ...$columns): self
    {
        $this->primary = $columns === [] ? [] : array_values($columns);

        return $this;
    }

    /** Add a secondary index; $columns entries are 'col' or ['col', 'ASC'|'DESC']. */
    public function index(array $columns, bool $unique = false, string $using = 'TREE', ?string $name = null): self
    {
        $this->indexes[] = new Index($name, $columns, $using, $unique);

        return $this;
    }

    /** Storage engine: memtx (default) or vinyl. */
    public function engine(string $engine): self
    {
        $this->engine = strtolower($engine);

        return $this;
    }

    /** Distribute by these columns instead of the primary key. */
    public function distributedBy(string ...$columns): self
    {
        $this->distributed = $columns;

        return $this;
    }

    /** Replicate everywhere: DISTRIBUTED GLOBALLY (no shards). */
    public function globally(): self
    {
        $this->distributed = null;

        return $this;
    }

    /** Place shards in a named tier (DISTRIBUTED BY ... IN TIER name). */
    public function tier(?string $tier): self
    {
        $this->tier = $tier;

        return $this;
    }

    /** Do not write a WAL for this table. */
    public function unlogged(bool $unlogged = true): self
    {
        $this->unlogged = $unlogged;

        return $this;
    }

    /** The physical table name (used verbatim, resolver-free). */
    public function name(): string
    {
        return $this->name;
    }

    /** Materialize the immutable schema description. */
    public function toTable(): Table
    {
        $primary = $this->primary ?? (array_values(array_map(
            static fn (Column $c): string => $c->name,
            array_filter($this->columns, static fn (Column $c): bool => $c->primary),
        )));

        if ($primary === [] && $this->columns !== []) {
            $primary = [$this->columns[0]->name];
        }

        $columns = array_map(
            static fn (Column $c): Column => in_array($c->name, $primary, true)
                ? new Column($c->name, $c->type, $c->nullable, $c->default, $c->array, $c->unsigned, true)
                : $c,
            $this->columns,
        );

        return new Table(
            name: $this->name,
            columns: $columns,
            primary: $primary,
            engine: $this->engine,
            distributed: $this->distributed,
            tier: $this->tier,
            unlogged: $this->unlogged,
            indexes: $this->indexes,
        );
    }

    /** Register this model's metadata for its name and return the name (a valid target). */
    public function register(): string
    {
        $t = $this->toTable();
        ClassFactory::register($t->name, $t);

        return $t->name;
    }
}
