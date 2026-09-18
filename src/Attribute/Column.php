<?php

declare(strict_types=1);

namespace Basis\Picodata\Attribute;

/**
 * Column override. Every promoted constructor property (and every public
 * property) becomes a column by default: name = snake_case of the property,
 * SQL type from the PHP type via Types::sqlOf, nullability from the PHP
 * type. This attribute supplies only what the PHP type cannot express:
 * a column-name override, an exotic SQL `type`, or the UNSIGNED / ARRAY
 * column flags.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly bool $unsigned = false,
        public readonly bool $array = false,
    ) {
    }
}
