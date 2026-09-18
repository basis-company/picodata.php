<?php

declare(strict_types=1);

namespace Basis\Picodata\Attribute;

/**
 * Distribution key: maps to the `DISTRIBUTED BY (col, ...)` clause of
 * CREATE TABLE. Omit the attribute entirely for the default (distribute
 * by the primary key); use #[DistributedGlobally] for replicated tables.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Distributed
{
    /**
     * @param string[] $columns distribution key columns (or property names)
     */
    public function __construct(
        public readonly array $columns,
    ) {
    }
}
