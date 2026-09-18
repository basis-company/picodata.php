<?php

declare(strict_types=1);

namespace Basis\Picodata\Attribute;

/**
 * Marker: maps to the `DISTRIBUTED GLOBALLY` clause of CREATE TABLE — the
 * table is replicated to every shard instead of being sharded by key.
 * Mutually exclusive with #[Distributed].
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DistributedGlobally
{
}
