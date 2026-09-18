<?php

declare(strict_types=1);

namespace Basis\Picodata\Attribute;

/**
 * Marker: maps to the `UNLOGGED TABLE` clause of
 * `CREATE UNLOGGED TABLE ...` — writes skip the replication log.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Unlogged
{
}
