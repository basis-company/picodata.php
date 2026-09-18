<?php

declare(strict_types=1);

namespace Basis\Picodata\Map;

use Basis\Picodata\Exception\InvalidException;

/**
 * Type mapping between PHP and Picodata/SQL. Stateless helpers.
 */
final class Types
{
    /** SQL scalar types we recognise for phpOf(). */
    private const SCALARS = [
        'BOOLEAN' => 'bool',
        'INTEGER' => 'int',
        'INT' => 'int',
        'INTEGER UNSIGNED' => 'int',
        'DOUBLE' => 'float',
        'DECIMAL' => 'string',
        'TEXT' => 'string',
        'UUID' => 'string',
        'DATETIME' => 'DateTimeImmutable',
        'JSON' => 'string',
    ];

    /**
     * SQL type name for a PHP reflection type or a lowercase PHP type string.
     * array/mixed encode as TEXT (JSON). Unknown class types throw unless the
     * caller overrides via #[Column(type:)].
     */
    public static function sqlOf(\ReflectionNamedType|string $t): string
    {
        if ($t instanceof \ReflectionNamedType) {
            if ($t->isBuiltin()) {
                return match ($t->getName()) {
                    'int' => 'INTEGER',
                    'float' => 'DOUBLE',
                    'bool' => 'BOOLEAN',
                    'string' => 'TEXT',
                    'array' => 'TEXT',
                    'mixed' => 'TEXT',
                    default => throw new InvalidException("unsupported builtin type {$t->getName()}"),
                };
            }
            return self::classSql($t->getName());
        }

        return match (strtolower($t)) {
            'int', 'integer' => 'INTEGER',
            'float' => 'DOUBLE',
            'bool', 'boolean' => 'BOOLEAN',
            'string' => 'TEXT',
            'array', 'mixed' => 'TEXT',
            'datetimeimmutable', 'datetime', 'datetimeinterface' => 'DATETIME',
            default => str_contains($t, '\\') || class_exists($t)
                ? self::classSql($t)
                : throw new InvalidException("unknown type {$t}"),
        };
    }

    /** SQL type for a class name (enums resolve to their backing type). */
    private static function classSql(string $class): string
    {
        if (is_subclass_of($class, \BackedEnum::class) && enum_exists($class)) {
            $bt = (new \ReflectionEnum($class))->getBackingType();
            return $bt?->getName() === 'int' ? 'INTEGER' : 'TEXT';
        }
        return match (strtolower($class)) {
            'datetimeimmutable', 'datetime', 'datetimeinterface' => 'DATETIME',
            default => throw new InvalidException("cannot map type {$class}; set #[Column(type:)]"),
        };
    }

    /**
     * PHP type name for a SQL type, inverse of Table::phpType map.
     * 'ARRAY' suffix yields 'array'; unknown returns null.
     */
    public static function phpOf(string $sqlType): ?string
    {
        $t = strtoupper(trim($sqlType));
        if (str_ends_with($t, ' ARRAY')) {
            return 'array';
        }
        return self::SCALARS[$t] ?? null;
    }

    /**
     * Coerce a raw DB value toward a declared PHP type name. Never throws;
     * unparseable values are returned unchanged.
     */
    public static function cast(mixed $value, string $phpType): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($phpType) {
            'int', 'integer' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool', 'boolean' => self::toBool($value),
            'string' => $value instanceof \BackedEnum ? $value->value
                : (is_scalar($value) ? (string) $value : $value),
            'array' => self::toArray($value),
            'mixed' => $value,
            'DateTimeImmutable' => self::toDateTime($value),
            default => self::castEnum($value, $phpType),
        };
    }

    /** Convert a raw DB value into a bound query parameter. */
    public static function toParam(mixed $v): mixed
    {
        if ($v === null || is_scalar($v)) {
            return $v;
        }
        if ($v instanceof \BackedEnum) {
            return $v->value;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if ($v instanceof \Stringable) {
            return (string) $v;
        }
        throw new InvalidException('cannot bind value of type ' . get_debug_type($v));
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['t', 'true', '1', 'yes'], true);
        }
        return (bool) $v;
    }

    /** Decode a JSON or PostgreSQL '{a,b}' array literal into a PHP array. */
    private static function toArray(mixed $v): mixed
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v)) {
            return $v;
        }
        $s = trim($v);
        if ($s === '') {
            return [];
        }
        if ($s[0] === '[') {
            $d = json_decode($s, true);
            return is_array($d) ? $d : $v;
        }
        if ($s[0] === '{' && str_ends_with($s, '}')) {
            $d = json_decode($s, true);

            return is_array($d) ? $d : self::parsePgArrayLiteral(substr($s, 1, -1));
        }
        return $v;
    }

    /** Split a Postgres array body honoring double-quoted members. */
    private static function parsePgArrayLiteral(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $out = [];
        $cur = '';
        $inQuote = false;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];
            if ($inQuote) {
                if ($c === '\\' && $i + 1 < $len) {
                    $cur .= $body[++$i];
                } elseif ($c === '"') {
                    $inQuote = false;
                } else {
                    $cur .= $c;
                }
            } elseif ($c === '"') {
                $inQuote = true;
            } elseif ($c === ',') {
                $out[] = $cur;
                $cur = '';
            } else {
                $cur .= $c;
            }
        }
        $out[] = $cur;
        return $out;
    }

    private static function toDateTime(mixed $v): mixed
    {
        if ($v instanceof \DateTimeImmutable) {
            return $v;
        }
        if ($v instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($v);
        }
        if (is_int($v)) {
            return (new \DateTimeImmutable())->setTimestamp($v);
        }
        if (is_string($v)) {
            try {
                return new \DateTimeImmutable($v);
            } catch (\Throwable) {
                return $v;
            }
        }
        return $v;
    }

    /** Back the enum case from its stored scalar; falls through otherwise. */
    private static function castEnum(mixed $v, string $class): mixed
    {
        if ($v instanceof $class) {
            return $v;
        }
        if (enum_exists($class) && is_subclass_of($class, \BackedEnum::class)) {
            $backing = (new \ReflectionEnum($class))->getBackingType()?->getName();
            $scalar = $backing === 'int' ? (is_numeric($v) ? (int) $v : $v) : (string) $v;
            try {
                return $class::from($scalar);
            } catch (\Throwable) {
                return $v;
            }
        }
        return $v;
    }
}
