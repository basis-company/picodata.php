<?php

declare(strict_types=1);

namespace Basis\Picodata\Attribute;

/**
 * Storage engine selector: maps to the `USING {engine}` clause of
 * `CREATE TABLE ... USING {memtx|vinyl}`. Omit for the memtx default.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Engine
{
    public function __construct(
        public readonly string $engine,
    ) {
    }
}
