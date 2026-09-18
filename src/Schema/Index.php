<?php

declare(strict_types=1);

namespace Basis\Picodata\Schema;

/**
 * Secondary index definition:
 * `CREATE [UNIQUE] INDEX name ON t USING {using} (cols)`.
 */
final class Index
{
    /**
     * @param ?string $name index name; null lets Ddl auto-generate `{table}_{cols}_idx`
     * @param array   $columns column entries: 'col' or ['col', 'ASC'|'DESC']
     * @param string  $using access method, one of TREE|HASH|RTREE|BITSET
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly array $columns = [],
        public readonly string $using = 'TREE',
        public readonly bool $unique = false,
    ) {
    }
}
