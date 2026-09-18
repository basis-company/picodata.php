<?php

declare(strict_types=1);

namespace Basis\Picodata\Map;

/**
 * Bidirectional entity <-> table naming rule.
 *
 * The mapper asks {@see tableOf()} to turn an entity class into a physical
 * table name (a `#[TableName]` attribute on the class still wins over the
 * rule), and {@see classOf()} to turn a physical table back into an entity
 * class when rows are loaded by name rather than by class.
 *
 * Picodata has no schemas/namespaces — table names are flat and unique
 * cluster-wide — so a module boundary can only be expressed in the name
 * itself. A resolver is that mapping: give it your own rules by implementing
 * this interface, or use {@see PrefixedResolver} (namespace -> prefix map).
 *
 * Class-backed naming only. Dynamic {@see \Basis\Picodata\Schema\Model}s
 * carry an explicit name and never consult the resolver.
 */
interface Resolver
{
    /** Physical table name for an entity class FQCN. */
    public function tableOf(string $class): string;

    /**
     * Entity class FQCN for a physical table name, or null when this rule has
     * no mapping for it. The mapper then reuses an autoloadable class or
     * generates a runtime one once (via ClassFactory) — so returning null is a
     * valid answer for tables you never mapped to an app class.
     */
    public function classOf(string $table): ?string;
}
