<?php

declare(strict_types=1);

namespace Basis\Picodata;

use Basis\Picodata\Attribute\DistributedGlobally;
use Basis\Picodata\Attribute\TableName;
use Basis\Picodata\Schema\Index;

/**
 * Which listener wants changes of which table ('*' matches every table).
 */
#[TableName('picodata_subscription')]
#[DistributedGlobally]
final class Subscription implements Indexing
{
    public function __construct(
        public string $id,
        public string $listener,
        public string $tablename,
    ) {
    }

    /** @return list<Index> */
    public static function indexes(): array
    {
        return [
            new Index(columns: ['listener', 'tablename'], unique: true),
        ];
    }
}
