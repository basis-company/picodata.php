<?php

declare(strict_types=1);

namespace Basis\Picodata\Schema;

use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Quoter;

/**
 * Picodata DDL generator: CREATE/DROP TABLE and INDEX statements.
 */
final class Ddl
{
    private function __construct()
    {
    }

    /**
     * CREATE [UNLOGGED] TABLE ... USING engine DISTRIBUTED ... statements.
     */
    public static function createTable(Table $t): string
    {
        $global = $t->distributed === null;
        if (!$global && $t->primary === [] && $t->distributed === []) {
            throw new InvalidException('sharded table requires primary key');
        }
        if ($global && strcasecmp($t->engine, 'memtx') !== 0) {
            throw new InvalidException('global tables require memtx engine');
        }

        $defs = [];
        foreach ($t->columns as $column) {
            $defs[] = self::column($column);
        }
        if ($t->primary !== []) {
            $defs[] = 'PRIMARY KEY (' . Quoter::list($t->primary) . ')';
        }

        $sql = 'CREATE ' . ($t->unlogged ? 'UNLOGGED ' : '') . 'TABLE ' . Quoter::identifier($t->name);
        $sql .= ' (' . implode(', ', $defs) . ')';
        $sql .= ' USING ' . $t->engine;
        $sql .= $global
            ? ' DISTRIBUTED GLOBALLY'
            : ' DISTRIBUTED BY (' . Quoter::list($t->distributed ?: $t->primary) . ')';
        if ($t->tier !== null) {
            $sql .= ' IN TIER ' . Quoter::identifier($t->tier);
        }
        if ($t->waitApplied) {
            $sql .= ' WAIT APPLIED GLOBALLY';
        }
        if ($t->timeout !== null) {
            $sql .= ' OPTION (TIMEOUT = ' . var_export($t->timeout, true) . ')';
        }

        return $sql;
    }

    /**
     * CREATE [UNIQUE] INDEX ... ON ... USING method (cols [ASC|DESC]);
     * an unnamed index gets `{table}_{cols}_idx`.
     */
    public static function createIndex(Table $t, Index $i): string
    {
        $parts = [];
        foreach ($i->columns as $col) {
            if (is_array($col)) {
                $dir = strtoupper((string) ($col[1] ?? ''));
                $parts[] = Quoter::identifier((string) $col[0])
                    . ($dir === '' ? '' : ' ' . $dir);
            } else {
                $parts[] = Quoter::identifier((string) $col);
            }
        }

        return 'CREATE ' . ($i->unique ? 'UNIQUE ' : '') . 'INDEX ' . Quoter::identifier(self::indexName($t, $i))
            . ' ON ' . Quoter::identifier($t->name)
            . ' USING ' . $i->using
            . ' (' . implode(', ', $parts) . ')';
    }

    /**
     * The index name: the explicit one, or the generated `{table}_{cols}_idx`.
     * Live and generated names must agree for diff() to recognize indexes.
     */
    public static function indexName(Table $t, Index $i): string
    {
        if ($i->name !== null) {
            return $i->name;
        }

        $names = [];
        foreach ($i->columns as $col) {
            $names[] = strtolower((string) (is_array($col) ? $col[0] : $col));
        }

        return strtolower(implode('_', [
            str_replace('.', '_', $t->name),
            ...$names,
            'idx',
        ]));
    }

    /**
     * ALTER TABLE ... ADD COLUMN IF NOT EXISTS <definition>: the column
     * migration Picodata supports in place (types are immutable, there is
     * no DROP COLUMN).
     */
    public static function addColumn(Table $t, Column $c): string
    {
        return 'ALTER TABLE ' . Quoter::identifier($t->name)
            . ' ADD COLUMN IF NOT EXISTS ' . self::column($c);
    }

    /**
     * DROP TABLE IF EXISTS.
     */
    public static function dropTable(Table|string $t): string
    {
        $name = $t instanceof Table ? $t->name : $t;

        return 'DROP TABLE IF EXISTS ' . Quoter::identifier($name);
    }

    /**
     * DROP INDEX IF EXISTS.
     */
    public static function dropIndex(string $name): string
    {
        return 'DROP INDEX IF EXISTS ' . Quoter::identifier($name);
    }

    /**
     * Single column definition.
     */
    private static function column(Column $c): string
    {
        $sql = Quoter::identifier($c->name) . ' ' . strtoupper($c->type);
        if ($c->unsigned) {
            $sql .= ' UNSIGNED';
        }
        if ($c->array) {
            $sql .= ' ARRAY';
        }
        if (!$c->nullable) {
            $sql .= ' NOT NULL';
        }
        if ($c->default !== null) {
            $sql .= ' DEFAULT ' . ($c->default instanceof Expression
                ? $c->default->sql
                : Quoter::literal($c->default));
        }

        return $sql;
    }
}
