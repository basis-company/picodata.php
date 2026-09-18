<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Attribute\TableName;
use Basis\Picodata\Driver;
use Basis\Picodata\Picodata;
use Basis\Picodata\Result;
use PHPUnit\Framework\TestCase;

#[TableName('map_user')]
class MapUser
{
    public function __construct(
        public int $id,
        public string $username,
    ) {
    }
}

final class IdentityMapTest extends TestCase
{
    /** @var list<array{sql: string, params: array}> */
    private array $log = [];

    private function db(array $queue): Picodata
    {
        $this->log = [];
        $fake = new class ($this->log, $queue) implements Driver {
            /** @param list<array{rows?: list<array>, affected?: int}> $queue */
            public function __construct(private array &$log, private array $queue)
            {
            }

            public function statement(string $sql, array $params = []): Result
            {
                $next = array_shift($this->queue) ?? ['rows' => []];

                return new Result($next['rows'] ?? [], $next['affected'] ?? 0);
            }

            public function driverName(): string
            {
                return 'fake';
            }
        };

        return new Picodata($fake);
    }

    public function test_refetch_refreshes_same_instance(): void
    {
        $db = $this->db([
            ['rows' => [['id' => '1', 'username' => 'nekufa']]],
            ['rows' => [['id' => '1', 'username' => 'renamed']]],
        ]);

        $first = $db->find(MapUser::class)[0];
        $again = $db->findOne(MapUser::class, ['id' => 1]);

        self::assertSame($first, $again);
        self::assertSame('renamed', $first->username);
    }

    public function test_update_registers_entity_in_map(): void
    {
        $db = $this->db([
            ['rows' => [['id' => '1', 'username' => 'a']]],
            ['affected' => 1],
            ['rows' => [['id' => '1', 'username' => 'b']]],
        ]);

        $user = $db->find(MapUser::class)[0];
        self::assertSame(1, $db->update($user, ['username' => 'b']));

        $again = $db->find(MapUser::class)[0];

        self::assertSame($user, $again);
        self::assertSame('b', $user->username);
    }

    public function test_delete_evicts_from_map(): void
    {
        $db = $this->db([
            ['rows' => [['id' => '1', 'username' => 'a']]],
            ['affected' => 1],
            ['rows' => [['id' => '1', 'username' => 'a']]],
        ]);

        $user = $db->find(MapUser::class)[0];
        self::assertSame(1, $db->delete(MapUser::class, 1));

        self::assertNotSame($user, $db->find(MapUser::class)[0]);
    }

    public function test_clear_identity_hydrates_fresh_objects(): void
    {
        $db = $this->db([
            ['rows' => [['id' => '1', 'username' => 'a']]],
            ['rows' => [['id' => '1', 'username' => 'a']]],
        ]);

        $user = $db->find(MapUser::class)[0];
        $db->clearIdentity();

        self::assertNotSame($user, $db->find(MapUser::class)[0]);
    }
}
