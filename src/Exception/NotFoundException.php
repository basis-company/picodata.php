<?php

declare(strict_types=1);

namespace Basis\Picodata\Exception;

/**
 * Requested row does not exist.
 */
final class NotFoundException extends PicodataException
{
    /**
     * Build a "no row" error for a table and its WHERE criteria.
     */
    public static function forTable(string $table, array $where): static
    {
        return new static(sprintf(
            'No row in %s matching %s',
            $table,
            json_encode($where, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));
    }
}
