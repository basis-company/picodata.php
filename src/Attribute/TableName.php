<?php

declare(strict_types=1);

namespace Basis\Picodata\Attribute;

/**
 * Table name override: supplies the TABLE name token of
 * `CREATE TABLE {name} (...)`. Default is the snake_case short class name.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TableName
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
