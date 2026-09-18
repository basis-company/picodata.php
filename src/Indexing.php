<?php

declare(strict_types=1);

namespace Basis\Picodata;

/**
 * Implemented by entities that declare secondary indexes — the
 * `CREATE [UNIQUE] INDEX ... ON ... USING ...` clauses of their table.
 * Index names may be null; Ddl auto-names them `{table}_{cols}_idx`.
 */
interface Indexing
{
    /**
     * @return \Basis\Picodata\Schema\Index[] secondary index definitions
     */
    public static function indexes(): array;
}
