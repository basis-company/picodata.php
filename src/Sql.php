<?php

declare(strict_types=1);

namespace Basis\Picodata;

use Basis\Picodata\Exception\InvalidException;

/**
 * Static SQL builder for the Picodata dialect.
 *
 * Emits `?` placeholders; the driver rewrites them to positional parameters.
 * Parameters are collected in statement order: SET values before WHERE values.
 */
final class Sql
{
    /** Comparison operators allowed in WHERE condition arrays. */
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE'];

    /** Identifier (optionally dotted) accepted before quoting. */
    private const NAME_RE = '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/';

    /**
     * SELECT $columns FROM $table WHERE ... [ORDER BY ...] [LIMIT n] [OFFSET n].
     *
     * Options: order — 'id DESC' string, ['id', 'name'] list or ['id' => 'DESC'] map;
     * limit/offset — inline integers. WHERE clause is omitted when $where is empty.
     */
    public static function select(string $table, array $columns = ['*'], array $where = [], array $options = []): Query
    {
        $params = [];
        $sql = 'SELECT ' . self::columnList($columns)
            . ' FROM ' . Quoter::identifier($table);

        $whereSql = self::where($where, $params);
        if ($whereSql !== '1') {
            $sql .= ' WHERE ' . $whereSql;
        }

        $order = self::order($options['order'] ?? null);
        if ($order !== null) {
            $sql .= ' ORDER BY ' . $order;
        }

        foreach (['limit', 'offset'] as $key) {
            if (array_key_exists($key, $options)) {
                $sql .= ' ' . strtoupper($key) . ' ' . self::integer($options[$key], $key);
            }
        }

        return new Query($sql, $params);
    }

    /**
     * Render a WHERE clause from a column => condition map.
     *
     * Rules: null => IS NULL; non-empty scalar list => IN (?,...);
     * operator map ['>=' => 5] => "col" >= ?; scalar => "col" = ?.
     * Empty map renders as '1'. Conditions are joined with AND.
     *
     * @param array $params appended with bound values in clause order
     */
    public static function where(array $where, array &$params): string
    {
        if ($where === []) {
            return '1';
        }

        $parts = [];
        foreach ($where as $column => $value) {
            $parts[] = self::condition((string) $column, $value, $params);
        }

        return implode(' AND ', $parts);
    }

    /**
     * INSERT INTO $table ("a","b") VALUES (?,?).
     *
     * $onConflict is a raw suffix (e.g. 'ON CONFLICT DO NOTHING'), appended
     * as-is; anything not starting with ON CONFLICT is rejected.
     */
    public static function insert(string $table, array $values, ?string $onConflict = null): Query
    {
        if ($values === []) {
            throw new InvalidException('INSERT requires at least one column');
        }

        $columns = [];
        foreach (array_keys($values) as $column) {
            $columns[] = self::name((string) $column);
        }

        $sql = 'INSERT INTO ' . Quoter::identifier($table)
            . ' (' . implode(',', $columns) . ') VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')';

        if ($onConflict !== null) {
            if (!preg_match('/^\s*ON\s+CONFLICT\b/i', $onConflict)) {
                throw new InvalidException('ON CONFLICT clause must start with ON CONFLICT');
            }
            $sql .= ' ' . $onConflict;
        }

        return new Query($sql, array_values($values));
    }

    /** UPDATE $table SET "a" = ? WHERE ... ; SET params precede WHERE params. */
    public static function update(string $table, array $changes, array $where): Query
    {
        if ($changes === []) {
            throw new InvalidException('UPDATE requires at least one changed column');
        }

        $params = [];
        $sets = [];
        foreach ($changes as $column => $value) {
            $sets[] = self::name((string) $column) . ' = ?';
            $params[] = $value;
        }

        $sql = 'UPDATE ' . Quoter::identifier($table) . ' SET ' . implode(', ', $sets);

        return new Query($sql . ' WHERE ' . self::where($where, $params), $params);
    }

    /** DELETE FROM $table WHERE ... */
    public static function delete(string $table, array $where): Query
    {
        $params = [];

        return new Query(
            'DELETE FROM ' . Quoter::identifier($table) . ' WHERE ' . self::where($where, $params),
            $params
        );
    }

    /** Render a column list; '*' passes through unquoted. */
    private static function columnList(array $columns): string
    {
        if ($columns === []) {
            return '*';
        }

        $parts = [];
        foreach ($columns as $column) {
            $column = (string) $column;
            $parts[] = $column === '*' ? '*' : self::name($column);
        }

        return implode(',', $parts);
    }

    /** Render ORDER BY from string / list / column=>direction map; null when absent. */
    private static function order(mixed $order): ?string
    {
        if ($order === null || $order === []) {
            return null;
        }

        if (is_string($order)) {
            $order = preg_split('/\s*,\s*/', trim($order), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (!is_array($order)) {
            throw new InvalidException('ORDER must be a string or an array');
        }

        $parts = [];
        foreach ($order as $column => $direction) {
            if (is_int($column)) {
                if (!is_string($direction)) {
                    throw new InvalidException('ORDER term must be a string');
                }
                if (!preg_match('/^(\S+)(?:\s+(.*))?$/i', trim($direction), $m)) {
                    throw new InvalidException('Invalid ORDER term');
                }
                $parts[] = self::name($m[1]) . (isset($m[2]) && $m[2] !== '' ? ' ' . self::direction($m[2]) : '');
            } else {
                $parts[] = self::name((string) $column) . ' ' . self::direction((string) $direction);
            }
        }

        return implode(',', $parts);
    }

    /** Validate ASC/DESC with optional NULLS FIRST/LAST. */
    private static function direction(string $direction): string
    {
        if (!preg_match('/^(ASC|DESC)(\s+NULLS\s+(FIRST|LAST))?$/i', trim($direction), $m)) {
            throw new InvalidException('Invalid ORDER direction: ' . $direction);
        }

        $out = strtoupper($m[1]);
        if (isset($m[3])) {
            $out .= ' NULLS ' . strtoupper($m[3]);
        }

        return $out;
    }

    /** Validate and quote one identifier. */
    private static function name(string $name): string
    {
        if (!preg_match(self::NAME_RE, $name)) {
            throw new InvalidException('Invalid identifier: ' . $name);
        }

        return Quoter::identifier($name);
    }

    /** Render one WHERE condition, appending its bound values to $params. */
    private static function condition(string $column, mixed $value, array &$params): string
    {
        $quoted = self::name($column);

        if ($value === null) {
            return $quoted . ' IS NULL';
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                if ($value === []) {
                    throw new InvalidException('Empty IN list for column: ' . $column);
                }
                $in = [];
                foreach ($value as $item) {
                    if (!is_scalar($item)) {
                        throw new InvalidException('IN list must contain scalars: ' . $column);
                    }
                    $in[] = '?';
                    $params[] = $item;
                }
                return $quoted . ' IN (' . implode(',', $in) . ')';
            }

            $parts = [];
            foreach ($value as $operator => $operand) {
                $operator = strtoupper((string) $operator);
                if (!in_array($operator, self::OPERATORS, true)) {
                    throw new InvalidException('Unsupported operator: ' . $operator);
                }
                $parts[] = $quoted . ' ' . $operator . ' ?';
                $params[] = $operand;
            }
            return implode(' AND ', $parts);
        }

        if (!is_scalar($value)) {
            throw new InvalidException('Unsupported WHERE value for column: ' . $column);
        }

        $params[] = $value;
        return $quoted . ' = ?';
    }

    /** Validate an inline integer option (LIMIT/OFFSET). */
    private static function integer(mixed $value, string $what): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidException('Option ' . $what . ' must be a non-negative integer');
        }

        return $value;
    }
}
