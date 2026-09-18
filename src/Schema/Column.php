<?php

declare(strict_types=1);

namespace Basis\Picodata\Schema;

/**
 * Column definition: name, SQL type, nullability and flags.
 *
 * Type constants spell the SQL scalars Picodata understands; `array` and
 * `unsigned` are declared separately (rendered as `TYPE ARRAY` /
 * `TYPE UNSIGNED`).
 */
final class Column
{
    public const BOOLEAN = 'BOOLEAN';
    public const INTEGER = 'INTEGER';
    public const INT = 'INT';
    public const DOUBLE = 'DOUBLE';
    public const DECIMAL = 'DECIMAL';
    public const TEXT = 'TEXT';
    public const UUID = 'UUID';
    public const DATETIME = 'DATETIME';
    public const JSON = 'JSON';

    public function __construct(
        public readonly string $name,
        public readonly string $type = self::INTEGER,
        public readonly bool $nullable = false,
        public readonly mixed $default = null,
        public readonly bool $array = false,
        public readonly bool $unsigned = false,
        public readonly bool $primary = false,
    ) {
    }
}
