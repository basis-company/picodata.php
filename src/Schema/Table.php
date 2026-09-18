<?php

declare(strict_types=1);

namespace Basis\Picodata\Schema;

/**
 * Table definition: columns, distribution, engine, indexes.
 *
 * $distributed: list of column names, or null for DISTRIBUTED GLOBALLY.
 */
final class Table
{
    /** @var array<string, Column> columns keyed by name */
    public readonly array $columns;

    /**
     * @param string $name table name, may be schema-qualified ("auth.user")
     * @param Column[] $columns columns, stored as a name-keyed map
     * @param string[] $primary primary key columns
     * @param string $engine memtx|vinyl
     * @param string[]|null $distributed distribution columns, null = global
     * @param string|null $tier storage tier for DISTRIBUTED BY
     * @param Index[] $indexes secondary indexes
     * @param float|null $timeout DDL apply timeout, seconds
     */
    public function __construct(
        public readonly string $name,
        array $columns = [],
        public readonly array $primary = [],
        public readonly string $engine = 'memtx',
        public readonly ?array $distributed = [],
        public readonly ?string $tier = null,
        public readonly bool $unlogged = false,
        public readonly array $indexes = [],
        public readonly ?float $timeout = null,
        public readonly bool $waitApplied = false,
    ) {
        $byName = [];
        foreach ($columns as $column) {
            $byName[$column->name] = $column;
        }

        $this->columns = $byName;
    }

    /**
     * PHP type of a column (contract map), null when column unknown.
     */
    public function phpType(string $col): ?string
    {
        if (!isset($this->columns[$col])) {
            return null;
        }

        return match (strtoupper($this->columns[$col]->type)) {
            Column::TEXT => 'string',
            Column::INTEGER, Column::INT => '?int',
            Column::DOUBLE => '?float',
            Column::BOOLEAN => '?bool',
            Column::DATETIME => '?\\DateTimeImmutable',
            Column::UUID => '?string',
            Column::DECIMAL, Column::JSON => '?string',
            default => null,
        };
    }
}
