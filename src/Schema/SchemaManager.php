<?php

declare(strict_types=1);

namespace Basis\Picodata\Schema;

use Basis\Picodata\Driver;
use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Exception\NotFoundException;
use Basis\Picodata\Map\ClassFactory;
use Basis\Picodata\Map\Generator;
use Basis\Picodata\Map\Mapper;

/**
 * Live schema operations against _pico_table / _pico_index.
 */
final class SchemaManager
{
    public function __construct(
        private readonly Driver $driver,
        private readonly ?Mapper $mapper = null,
    ) {
    }

    /**
     * Whether a table is registered globally.
     */
    public function exists(string $table): bool
    {
        $rows = $this->driver->statement(
            'SELECT name FROM _pico_table WHERE name = ?',
            [strtolower($table)],
        )->all();

        return $rows !== [];
    }

    /**
     * Introspect a table into a Table object; registers it in ClassFactory.
     */
    public function load(string $table): Table
    {
        $name = strtolower($table);
        $rows = $this->driver->statement(
            'SELECT name, distribution, format, engine, opts FROM _pico_table WHERE name = ?',
            [$name],
        )->all();
        if ($rows === []) {
            throw new NotFoundException("Table $table not found");
        }
        $row = self::arr($rows[0]);

        $opts = (array) self::decode($row['opts'] ?? null);
        $skipBucket = !empty($opts['pk_contains_bucket_id'][0]);

        $global = false;
        $distributed = [];
        $tier = null;
        $distribution = self::decode($row['distribution'] ?? null);
        if ($distribution === null) {
            $global = true;
        } else {
            $distribution = self::arr($distribution);
            if (array_key_exists('Global', $distribution)) {
                $global = true;
            } elseif (array_key_exists('ShardedImplicitly', $distribution)) {
                $tuple = array_values(self::arr($distribution['ShardedImplicitly']));
                $distributed = array_map('strval', array_values((array) ($tuple[0] ?? [])));
                $tier = isset($tuple[2]) ? (string) $tuple[2] : null;
            } elseif (array_key_exists('ShardedByField', $distribution)) {
                $tuple = array_values(self::arr($distribution['ShardedByField']));
                $distributed = [(string) ($tuple[0] ?? '')];
                $tier = isset($tuple[1]) ? (string) $tuple[1] : null;
            }
        }

        // Sharded tables carry a synthetic unsigned bucket_id in their format: it is
        // picodata's internal routing column, not a user column, so drop it here just
        // as the primary-key path below excludes it.
        $sharded = !$global;

        $columns = [];
        foreach ((array) self::decode($row['format'] ?? '[]') as $field) {
            $field = self::arr($field);
            $fieldName = (string) ($field['name'] ?? '');
            if ($fieldName === 'bucket_id' && ($sharded || $skipBucket)) {
                continue;
            }
            $columns[] = self::buildColumn(
                $fieldName,
                (string) ($field['field_type'] ?? 'integer'),
                (bool) ($field['is_nullable'] ?? false),
            );
        }

        $indexes = [];
        $primary = [];
        $indexRows = $this->driver->statement(
            'SELECT id, name, type, opts, parts FROM _pico_index'
            . ' WHERE table_id = (SELECT id FROM _pico_table WHERE name = ?)',
            [$name],
        )->all();
        foreach ($indexRows as $indexRow) {
            $indexRow = self::arr($indexRow);
            $parts = self::parts(self::decode($indexRow['parts'] ?? '[]'));
            if ((int) ($indexRow['id'] ?? -1) === 0) {
                $primary = array_values(array_filter(
                    array_map(static fn ($p) => is_array($p) ? (string) $p[0] : (string) $p, $parts),
                    static fn (string $n): bool => !$skipBucket || $n !== 'bucket_id',
                ));
                continue;
            }
            $indexOpts = self::decode($indexRow['opts'] ?? null);
            if (isset($indexOpts[0]) && is_array($indexOpts[0])) {
                $indexOpts = $indexOpts[0];
            }
            $indexes[] = new Index(
                (string) ($indexRow['name'] ?? ''),
                $parts,
                strtoupper((string) ($indexRow['type'] ?? 'TREE')),
                (bool) (self::arr($indexOpts)['unique'] ?? false),
            );
        }

        if ($primary !== []) {
            $columns = array_map(
                static function (Column $c) use ($primary): Column {
                    return in_array($c->name, $primary, true)
                        ? new Column(
                            $c->name,
                            $c->type,
                            $c->nullable,
                            $c->default,
                            $c->array,
                            $c->unsigned,
                            true
                        )
                        : $c;
                },
                $columns,
            );
        }

        $table = new Table(
            name: (string) ($row['name'] ?? $name),
            columns: $columns,
            primary: $primary,
            engine: strtolower((string) ($row['engine'] ?? 'memtx')),
            distributed: $global ? null : $distributed,
            tier: $tier,
            indexes: $indexes,
        );
        ClassFactory::register($table->name, $table);

        return $table;
    }

    /**
     * Create a table (from a class-free Model, a Table or an entity class name)
     * and its indexes. Returns the executed SQL.
     *
     * @return list<string>
     */
    public function create(string|Table|Model $t): array
    {
        $t = $this->code($t);

        $sql = [Ddl::createTable($t)];
        foreach ($t->indexes as $index) {
            $sql[] = Ddl::createIndex($t, $index);
        }
        foreach ($sql as $statement) {
            $this->driver->statement($statement);
        }
        ClassFactory::register($t->name, $t);

        return $sql;
    }

    /**
     * What migrate() would find: a missing table, columns and indexes the
     * class declares but the cluster lacks, and any drift that cannot be
     * fixed automatically (column types, primary key, engine, distribution).
     */
    public function diff(string|Table|Model $t): SchemaDiff
    {
        $t = $this->code($t);
        if (!$this->exists($t->name)) {
            return new SchemaDiff(missingTable: true);
        }

        return SchemaDiff::compare($t, $this->load($t->name));
    }

    /**
     * Bring the live schema up to date with the declaration: create the
     * table when absent, add columns the class or Model grew, create missing
     * indexes. Picodata cannot change a column type in place or drop
     * columns; such drift is left to the operator (see diff()->issues).
     * Returns the SQL actually executed (empty when already current).
     *
     * @return list<string>
     */
    public function migrate(string|Table|Model $t): array
    {
        $t = $this->code($t);
        if (!$this->exists($t->name)) {
            return $this->create($t);
        }

        $diff = SchemaDiff::compare($t, $this->load($t->name));

        $sql = [];
        foreach ($diff->addColumns as $column) {
            $statement = Ddl::addColumn($t, $column);
            $this->driver->statement($statement);
            $sql[] = $statement;
        }
        foreach ($diff->addIndexes as $index) {
            $statement = Ddl::createIndex($t, $index);
            $this->driver->statement($statement);
            $sql[] = $statement;
        }

        ClassFactory::register($t->name, $t);

        return $sql;
    }

    /** Normalize a declared schema source (class name, Table or Model) to a Table. */
    private function code(string|Table|Model $t): Table
    {
        if ($t instanceof Model) {
            $t = $t->toTable();
        }
        if (is_string($t)) {
            if ($this->mapper === null) {
                throw new InvalidException('mapper required to create table from class name');
            }
            $t = $this->mapper->table($t);
        }

        return $t;
    }

    /**
     * Drop a table (name, Table or Model).
     */
    public function drop(string|Table|Model $t): void
    {
        if ($t instanceof Model) {
            $t = $t->toTable();
        }

        $this->driver->statement(Ddl::dropTable($t));
    }

    /**
     * Create a secondary index.
     */
    public function createIndex(Table $t, Index $i): void
    {
        $this->driver->statement(Ddl::createIndex($t, $i));
    }

    /**
     * Drop an index.
     */
    public function dropIndex(string $name): void
    {
        $this->driver->statement(Ddl::dropIndex($name));
    }

    /**
     * Write an entity class source for a table; returns the file path.
     */
    public function generateClasses(string|Table $table, string $class, string $path): string
    {
        $t = is_string($table) ? $this->load($table) : $table;
        $source = Generator::source($t, $class, $this->mapper?->resolver());
        $pos = strrpos($class, '\\');
        $short = $pos === false ? $class : substr($class, $pos + 1);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $file = rtrim($path, '/') . '/' . $short . '.php';
        file_put_contents($file, $source);

        return $file;
    }

    /**
     * Map a _pico_table format entry to a Column.
     */
    private static function buildColumn(string $name, string $type, bool $nullable): Column
    {
        return match ($type) {
            'unsigned' => new Column($name, Column::INTEGER, $nullable, null, false, true),
            'array' => new Column($name, Column::TEXT, $nullable, null, true),
            'string', 'varbinary' => new Column($name, Column::TEXT, $nullable),
            'integer' => new Column($name, Column::INTEGER, $nullable),
            'double' => new Column($name, Column::DOUBLE, $nullable),
            'boolean' => new Column($name, Column::BOOLEAN, $nullable),
            'decimal' => new Column($name, Column::DECIMAL, $nullable),
            'uuid' => new Column($name, Column::UUID, $nullable),
            'datetime' => new Column($name, Column::DATETIME, $nullable),
            'json', 'map' => new Column($name, Column::JSON, $nullable),
            default => new Column($name, strtoupper($type), $nullable),
        };
    }

    /**
     * Normalize _pico_index parts to 'col' or ['col', 'ASC'|'DESC'] entries.
     */
    private static function parts(mixed $parts): array
    {
        $out = [];
        foreach ((array) $parts as $part) {
            if (is_string($part) && $part !== '' && in_array($part[0], ['[', '{'], true)) {
                $part = self::decode($part);
            }
            if (is_string($part)) {
                $out[] = $part;
                continue;
            }
            $part = self::arr($part);
            $name = (string) ($part['field'] ?? $part[0] ?? '');
            if (array_key_exists('asc', $part)) {
                $out[] = [$name, $part['asc'] ? 'ASC' : 'DESC'];
            } else {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Row to associative array (rows may arrive as stdClass).
     */
    private static function arr(mixed $row): array
    {
        return is_object($row) ? get_object_vars($row) : (array) $row;
    }

    /**
     * JSON-decode introspection values that arrive as strings.
     */
    private static function decode(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
}
