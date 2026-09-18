<?php

declare(strict_types=1);

namespace Basis\Picodata;

use Basis\Picodata\Exception\InvalidException;

/**
 * Identifier quoting, placeholder rewriting and literal rendering helpers.
 */
final class Quoter
{
    private function __construct()
    {
    }

    /**
     * Quote a (possibly dotted) SQL identifier; parts that are plain
     * lowercase names stay bare.
     */
    public static function identifier(string $name): string
    {
        $parts = [];
        foreach (explode('.', $name) as $part) {
            $parts[] = preg_match('/^[a-z_][a-z0-9_]*$/', $part) === 1
                ? $part
                : '"' . str_replace('"', '""', $part) . '"';
        }

        return implode('.', $parts);
    }

    /**
     * Comma-join a list of quoted identifiers.
     *
     * @param string[] $names
     */
    public static function list(array $names): string
    {
        return implode(', ', array_map([self::class, 'identifier'], $names));
    }

    /**
     * Rewrite `?` (positional) or `:name` (when $params is associative)
     * placeholders into driver-native $1..$n, returning ordered values.
     *
     * Placeholders inside single-quoted strings and double-quoted
     * identifiers are left untouched; `::` casts are preserved.
     *
     * @param array $params List of values, or name => value map.
     * @return array{string, array} [rewritten SQL, ordered values]
     *
     * @throws InvalidException On parameter mismatch.
     */
    public static function positional(string $sql, array $params): array
    {
        $named = !array_is_list($params);
        $len = strlen($sql);
        $out = '';
        $ordered = [];
        $index = 0;
        $used = 0;
        $names = [];

        for ($i = 0; $i < $len;) {
            $c = $sql[$i];

            if ($c === "'") {
                // Single-quoted string literal; '' is an escaped quote.
                $out .= $c;
                for ($i++; $i < $len; $i++) {
                    if ($sql[$i] === "'") {
                        if ($i + 1 < $len && $sql[$i + 1] === "'") {
                            $out .= "''";
                            $i++;
                            continue;
                        }
                        $out .= "'";
                        $i++;
                        break;
                    }
                    $out .= $sql[$i];
                }
                continue;
            }

            if ($c === '"') {
                // Double-quoted identifier.
                $out .= $c;
                for ($i++; $i < $len; $i++) {
                    $out .= $sql[$i];
                    if ($sql[$i] === '"') {
                        $i++;
                        break;
                    }
                }
                continue;
            }

            if ($c === '?') {
                $index++;
                $out .= '$' . $index;
                if (!$named && $params !== []) {
                    if ($used >= count($params)) {
                        throw new InvalidException(
                            "No value for placeholder \${$index} in: {$sql}",
                        );
                    }
                    $ordered[] = $params[$used++];
                }
                $i++;
                continue;
            }

            if ($c === ':') {
                if ($i + 1 < $len && $sql[$i + 1] === ':') {
                    $out .= '::'; // Cast operator.
                    $i += 2;
                    continue;
                }
                if ($named
                    && preg_match('/[a-zA-Z_][a-zA-Z0-9_]*/A', $sql, $m, 0, $i + 1) === 1
                ) {
                    $name = $m[0];
                    if (!array_key_exists($name, $params)) {
                        throw new InvalidException("Missing value for named parameter :{$name}");
                    }
                    if (!isset($names[$name])) {
                        $index++;
                        $names[$name] = $index;
                        $ordered[] = $params[$name];
                    }
                    $out .= '$' . $names[$name];
                    $i += 1 + strlen($name);
                    continue;
                }
                $out .= ':';
                $i++;
                continue;
            }

            $out .= $c;
            $i++;
        }

        if (!$named && $params !== [] && $used < count($params)) {
            throw new InvalidException(
                sprintf('Expected %d placeholders, got %d parameters in: %s', $used, count($params), $sql),
            );
        }

        return [$out, $ordered];
    }

    /**
     * Render a PHP value as a SQL literal (for DDL defaults etc.).
     */
    public static function literal(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if ($v instanceof \DateTimeInterface) {
            return "'" . $v->format('Y-m-d H:i:s') . "'";
        }
        if (is_string($v) || (is_object($v) && method_exists($v, '__toString'))) {
            return "'" . str_replace("'", "''", (string) $v) . "'";
        }

        throw new InvalidException('Cannot render SQL literal for ' . get_debug_type($v));
    }
}
