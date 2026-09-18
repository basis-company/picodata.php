<?php

declare(strict_types=1);

namespace Basis\Picodata\Schema;

/**
 * Raw SQL fragment, e.g. a DEFAULT CURRENT_TIMESTAMP column default.
 */
final class Expression
{
    public function __construct(
        public readonly string $sql,
    ) {
    }
}
