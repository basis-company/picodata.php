<?php

declare(strict_types=1);

namespace Basis\Picodata;

/**
 * SQL execution backend.
 */
interface Driver
{
    /**
     * Run a statement; `?` or `:name` placeholders bind $params.
     *
     * @param array $params Positional list or name => value map.
     */
    public function statement(string $sql, array $params = []): Result;

    /**
     * Backend identifier, e.g. 'pgsql'.
     */
    public function driverName(): string;
}
