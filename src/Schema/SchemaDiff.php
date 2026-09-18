<?php

declare(strict_types=1);

namespace Basis\Picodata\Schema;

/**
 * The delta between the schema a program declares (code) and the one the
 * cluster actually has (live).
 *
 * Picodata's ALTER TABLE can only add columns, so that is the single
 * auto-applicable column action; everything else that drifted is reported
 * in $issues for a human (or a migration script) to resolve.
 */
final class SchemaDiff
{
    /**
     * @param bool $missingTable the table does not exist yet and will be created
     * @param list<Column> $addColumns columns present in code but not live
     * @param list<Index> $addIndexes indexes present in code but not live
     * @param list<string> $issues human-readable, not auto-applicable drift
     */
    public function __construct(
        public readonly bool $missingTable = false,
        public readonly array $addColumns = [],
        public readonly array $addIndexes = [],
        public readonly array $issues = [],
    ) {
    }

    /** Nothing to create, add, or review: the schema is already current. */
    public function isEmpty(): bool
    {
        return !$this->missingTable
            && $this->addColumns === []
            && $this->addIndexes === []
            && $this->issues === [];
    }

    /** Compare a declared table against its live counterpart (same name). */
    public static function compare(Table $code, Table $live): self
    {
        $addColumns = [];
        $addIndexes = [];
        $issues = [];

        $liveColumns = [];
        foreach ($live->columns as $column) {
            $liveColumns[strtolower($column->name)] = $column;
        }

        $codeNames = [];
        foreach ($code->columns as $column) {
            $key = strtolower($column->name);
            $codeNames[$key] = true;

            if (!isset($liveColumns[$key])) {
                $addColumns[] = $column;

                continue;
            }

            $issue = self::columnDrift($column, $liveColumns[$key]);
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        foreach ($liveColumns as $key => $column) {
            if (!isset($codeNames[$key])) {
                $issues[] = "column {$column->name}: exists in the cluster, not in code "
                    . '(Picodata cannot drop columns; rename it with ALTER TABLE ... RENAME COLUMN to retire it)';
            }
        }

        if (strtolower($code->engine) !== strtolower($live->engine)) {
            $issues[] = "engine: live {$live->engine}, code {$code->engine} (immutable; recreate the table to change it)";
        }

        if (self::lower(self::names($code->primary)) !== self::lower(self::names($live->primary))) {
            $issues[] = 'primary key: live (' . implode(', ', $live->primary) . '), code ('
                . implode(', ', $code->primary) . ") (immutable; recreate the table to change it)";
        }

        $codeDist = self::lower(self::distributionColumns($code));
        $liveDist = self::lower(self::distributionColumns($live));
        if ($codeDist !== $liveDist || ($code->distributed === null) !== ($live->distributed === null)) {
            $issues[] = 'distribution: live ' . self::distributionSql($live) . ', code ' . self::distributionSql($code)
                . ' (immutable; recreate the table to change it)';
        }

        if ($code->tier !== null && $live->tier !== null && $code->tier !== $live->tier) {
            $issues[] = "tier: live {$live->tier}, code {$code->tier} (changeable only by moving the whole table)";
        }

        $liveIndexes = [];
        foreach ($live->indexes as $index) {
            $liveIndexes[strtolower($index->name ?? '')] = $index;
        }

        $codeIndexNames = [];
        foreach ($code->indexes as $index) {
            $name = $index->name ?? Ddl::indexName($code, $index);
            $codeIndexNames[strtolower($name)] = true;

            $existing = $liveIndexes[strtolower($name)] ?? null;
            if ($existing === null) {
                $addIndexes[] = $index->name === null ? new Index($name, $index->columns, $index->using, $index->unique) : $index;

                continue;
            }

            if (self::signature($index->columns) !== self::signature($existing->columns)
                || strtoupper($index->using) !== strtoupper($existing->using)
                || $index->unique !== $existing->unique) {
                $issues[] = "index $name: live and code definitions differ; recreate manually with dropIndex()/createIndex()";
            }
        }

        foreach ($liveIndexes as $name => $index) {
            if (!isset($codeIndexNames[$name])) {
                $issues[] = "index {$index->name}: exists in the cluster, not in code (drop manually if obsolete)";
            }
        }

        return new self(false, $addColumns, $addIndexes, $issues);
    }

    /** A drift note for one shared column, or null when they agree. */
    private static function columnDrift(Column $code, Column $live): ?string
    {
        $render = static fn (Column $c): string => strtoupper($c->type)
            . ($c->unsigned ? ' UNSIGNED' : '')
            . ($c->array ? ' ARRAY' : '')
            . ($c->nullable ? ' NULL' : ' NOT NULL');

        if ($render($live) !== $render($code)) {
            return "column {$code->name}: live {$render($live)}, code {$render($code)} "
                . '(Picodata cannot change a column type in place; add a new column and backfill, or recreate)';
        }

        return null;
    }

    /** @param list<string> $names @return list<string> */
    private static function names(array $primary): array
    {
        return array_values(array_map('strtolower', $primary));
    }

    /** @param list<string> $names @return list<string> */
    private static function lower(array $names): array
    {
        return array_map('strtolower', $names);
    }

    /** Index columns as one comparable string. */
    private static function signature(array $columns): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = is_array($column)
                ? strtolower((string) $column[0]) . ':' . strtolower((string) ($column[1] ?? ''))
                : strtolower((string) $column) . ':';
        }

        return implode(',', $parts);
    }

    /** Columns the table shards by: the declared list, or implicitly the primary key. */
    private static function distributionColumns(Table $t): array
    {
        return $t->distributed === [] ? $t->primary : ($t->distributed ?? []);
    }

    private static function distributionSql(Table $t): string
    {
        return $t->distributed === null
            ? 'DISTRIBUTED GLOBALLY'
            : 'DISTRIBUTED BY (' . implode(', ', $t->distributed) . ')';
    }
}
