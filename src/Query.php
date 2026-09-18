<?php

declare(strict_types=1);

namespace Basis\Picodata;

/**
 * An SQL statement with bound parameters; `?` placeholders allowed.
 */
final class Query implements \Stringable
{
    /**
     * @param string $sql   Statement text.
     * @param array  $params Positional list or name => value map.
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params = [],
    ) {
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
