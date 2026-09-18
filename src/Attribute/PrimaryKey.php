<?php

declare(strict_types=1);

namespace Basis\Picodata\Attribute;

/**
 * Explicit primary key: maps to the table-level `PRIMARY KEY (col, ...)`
 * clause of CREATE TABLE. Default (attribute omitted) is the first
 * promoted constructor property; pass an empty list for no primary key.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PrimaryKey
{
    /**
     * @param string[] $columns primary key columns, in order (or property names)
     */
    public function __construct(
        public readonly array $columns,
    ) {
    }
}
