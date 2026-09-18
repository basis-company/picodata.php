<?php

declare(strict_types=1);

namespace Basis\Picodata\Attribute;

/**
 * Storage tier selector: maps to the `IN TIER "x"` clause appended to
 * `CREATE TABLE ... DISTRIBUTED BY (...)`. Omit for the server default.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Tier
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
