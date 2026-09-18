<?php

declare(strict_types=1);

namespace Basis\Picodata;

use Basis\Picodata\Attribute\DistributedGlobally;
use Basis\Picodata\Attribute\TableName;
use Basis\Picodata\Schema\Index;

/**
 * One row per registered listener per committed write.
 *
 * Inserted in the SAME transaction as the data change, so a change is visible
 * exactly when the row it describes is (and never when it is not).
 * `data` holds the row after the change; `key` the addressing columns.
 */
#[TableName('picodata_change')]
#[DistributedGlobally]
final class Change implements Indexing
{
    public function __construct(
        public string $id,
        public string $listener,
        public string $tablename,
        public string $action,
        public array $data = [],
        public array $context = [],
        public ?\DateTimeImmutable $created_at = null,
    ) {
    }

    /** @return list<Index> */
    public static function indexes(): array
    {
        return [
            new Index(columns: ['listener', 'id']),
            new Index(columns: ['tablename']),
        ];
    }
}
