<?php

declare(strict_types=1);

namespace Basis\Picodata\Test\Integration;

use Basis\Picodata\Attribute\Engine;
use Basis\Picodata\Attribute\TableName;
use Basis\Picodata\Attribute\Tier;
use Basis\Picodata\Exception\NotFoundException;
use Basis\Picodata\Indexing;
use Basis\Picodata\Picodata;
use Basis\Picodata\Schema\Index;

#[TableName('it_user')]
#[Tier('hot')]
class ItUser implements Indexing
{
    public function __construct(
        public int $id,
        public string $username,
        public ?int $age = null,
        public ?bool $active = null,
    ) {
    }

    /** @return list<Index> */
    public static function indexes(): array
    {
        // On a sharded table Picodata requires every UNIQUE index to carry the
        // sharding key (id) as a prefix, so the lookup index is (id, username).
        return [new Index(columns: ['id', 'username'], unique: true)];
    }
}

#[TableName('it_log')]
#[Engine('vinyl')]
class ItLog
{
    public function __construct(
        public int $id,
        public string $msg,
    ) {
    }
}

final class ClusterTest extends IntegrationTestCase
{
    private const TABLES = ['it_user', 'it_log', 'it_raw', 'it_spot'];

    protected function tearDown(): void
    {
        $this->dropAll(self::TABLES);
        parent::tearDown();
    }

    public function test_ddl_from_attributes_on_live_cluster(): void
    {
        $this->db->schema()->create(ItUser::class);
        $this->db->schema()->create(ItLog::class);

        $user = $this->db->schema()->load('it_user');
        self::assertSame(['id'], $user->primary);
        self::assertSame(['id', 'username', 'age', 'active'], array_keys($user->columns));
        self::assertSame('hot', $user->tier);

        $log = $this->db->schema()->load('it_log');
        self::assertSame('vinyl', $log->engine);

        self::assertTrue($this->db->schema()->exists('it_user'));
        $names = array_column($user->indexes, 'name');
        self::assertNotEmpty($names);
    }

    public function test_schema_migrate_adds_columns_and_indexes(): void
    {
        // An "old" schema: the table ItUser had before the active column and
        // the unique lookup index were added, created by hand.
        $this->db->execute(
            'CREATE TABLE it_user (id INTEGER NOT NULL, username TEXT NOT NULL, age INTEGER, PRIMARY KEY (id))'
            . ' USING memtx DISTRIBUTED BY (id) IN TIER hot'
        );

        $applied = $this->db->schema()->migrate(ItUser::class);

        self::assertSame([
            'ALTER TABLE it_user ADD COLUMN IF NOT EXISTS active BOOLEAN',
            'CREATE UNIQUE INDEX it_user_id_username_idx ON it_user USING TREE (id, username)',
        ], $applied);

        $user = $this->db->schema()->load('it_user');
        self::assertSame(['id', 'username', 'age', 'active'], array_keys($user->columns));

        // Idempotent: the second run has nothing to apply.
        self::assertSame([], $this->db->schema()->migrate(ItUser::class));
        self::assertTrue($this->db->schema()->diff(ItUser::class)->isEmpty());

        // The new column is immediately usable.
        $this->db->insert(ItUser::class, ['id' => 1, 'username' => 'a', 'active' => true]);
        self::assertTrue($this->db->get(ItUser::class, 1)->active);
    }

    public function test_crud_roundtrip(): void
    {
        $this->db->schema()->create(ItUser::class);

        $created = $this->db->insert(ItUser::class, ['id' => 1, 'username' => 'nekufa', 'age' => 30, 'active' => true]);
        self::assertInstanceOf(ItUser::class, $created);
        self::assertSame('nekufa', $created->username);

        $found = $this->db->findOne(ItUser::class, ['username' => 'nekufa']);
        self::assertSame(1, $found->id);
        self::assertTrue($found->active);

        self::assertSame(1, $this->db->update(ItUser::class, 1, ['username' => 'neku']));
        self::assertSame('neku', $this->db->get(ItUser::class, 1)->username);

        $user = $this->db->get(ItUser::class, 1);
        self::assertSame(1, $this->db->update($user, ['age' => 31]));
        self::assertSame(31, $user->age);

        self::assertSame(1, $this->db->delete(ItUser::class, 1));
        self::assertNull($this->db->get(ItUser::class, 1));

        $this->expectException(NotFoundException::class);
        $this->db->findOrFail(ItUser::class, ['id' => 1]);
    }

    public function test_where_forms_order_limit(): void
    {
        $this->db->schema()->create(ItUser::class);
        foreach ([['a', 20], ['b', 30], ['c', 40]] as [$name, $age]) {
            $this->db->insert(ItUser::class, ['id' => crc32($name), 'username' => $name, 'age' => $age]);
        }

        self::assertCount(2, $this->db->find(ItUser::class, ['age' => ['>=' => 30]]));
        self::assertCount(2, $this->db->find(ItUser::class, ['username' => ['a', 'b']]));
        self::assertCount(0, $this->db->find(ItUser::class, ['age' => null]));

        $recent = $this->db->find(ItUser::class, [], ['order' => ['age' => 'DESC'], 'limit' => 2]);
        self::assertSame('c', $recent[0]->username);
        self::assertSame('b', $recent[1]->username);
    }

    public function test_save_inserts_then_updates(): void
    {
        $this->db->schema()->create(ItUser::class);

        $saved = $this->db->save(new ItUser(7, 'seven', 1, null));
        self::assertSame('seven', $saved->username);

        $saved->username = 'SEVEN';
        $again = $this->db->save($saved);
        self::assertSame('SEVEN', $this->db->get(ItUser::class, 7)->username);
        self::assertSame($saved, $again);
    }

    public function test_find_or_create(): void
    {
        $this->db->schema()->create(ItUser::class);

        $first = $this->db->findOrCreate(ItUser::class, ['username' => 'ghost'], ['id' => 99]);
        $second = $this->db->findOrCreate(ItUser::class, ['username' => 'ghost']);

        self::assertSame(99, $first->id);
        self::assertSame(99, $second->id);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) AS n FROM it_user')->all()[0]['n']);
    }

    public function test_nested_transaction_is_noop(): void
    {
        $this->db->schema()->create(ItUser::class);

        // Picodata's pgwire is autocommit-only and exposes no savepoints, so nested
        // transaction() is a no-op (see Picodata::transaction): both writes commit as
        // they run, and the inner throw only propagates — it rolls nothing back.
        try {
            $this->db->transaction(function (Picodata $db): void {
                $db->insert(ItUser::class, ['id' => 20, 'username' => 'outer']);

                try {
                    $db->transaction(function (Picodata $inner): void {
                        $inner->insert(ItUser::class, ['id' => 21, 'username' => 'inner']);

                        throw new \RuntimeException('inner fails');
                    });
                } catch (\RuntimeException) {
                    // no-op transaction: nothing to roll back
                }
            });
        } catch (\RuntimeException) {
        }

        self::assertNotNull($this->db->get(ItUser::class, 20));
        self::assertNotNull($this->db->get(ItUser::class, 21));
    }

    public function test_identity_map_against_real_rows(): void
    {
        $this->db->schema()->create(ItUser::class);
        $this->db->insert(ItUser::class, ['id' => 5, 'username' => 'a', 'age' => 1]);

        $user = $this->db->get(ItUser::class, 5);
        $refetched = $this->db->get(ItUser::class, 5);
        self::assertSame($user, $refetched);

        $this->db->execute('UPDATE it_user SET username = ? WHERE id = ?', ['b', 5]);
        $refreshed = $this->db->get(ItUser::class, 5);
        self::assertSame($user, $refreshed);
        self::assertSame('b', $user->username);
    }

    public function test_transaction_runs_and_propagates(): void
    {
        $this->db->schema()->create(ItUser::class);

        $this->db->transaction(function (Picodata $db): void {
            $db->insert(ItUser::class, ['id' => 10, 'username' => 'committed']);
        });
        self::assertNotNull($this->db->get(ItUser::class, 10));

        // Picodata is autocommit-only and transaction() is a no-op that cannot roll
        // back, so a write made before the throw is already committed. The exception
        // must still surface to the caller (the callable is run and errors propagate).
        try {
            $this->db->transaction(function (Picodata $db): void {
                $db->insert(ItUser::class, ['id' => 11, 'username' => 'autocommitted']);

                throw new \RuntimeException('boom');
            });
            self::fail('expected the exception to propagate through transaction()');
        } catch (\RuntimeException) {
        }

        self::assertNotNull($this->db->get(ItUser::class, 11));
    }

    public function test_raw_sql_with_placeholders(): void
    {
        $this->db->execute('CREATE TABLE it_raw (id INTEGER PRIMARY KEY, label TEXT) USING memtx');

        $rows = $this->db->query('SELECT ? AS one', [1])->all();
        self::assertSame('1', (string) $rows[0]['one']);

        $affected = $this->db->execute('INSERT INTO it_raw (id, label) VALUES (?, ?)', [1, 'hello']);
        self::assertSame(1, $affected);

        $fetched = $this->db->query('SELECT label FROM it_raw WHERE id = ?', [1])->all();
        self::assertSame('hello', $fetched[0]['label']);
    }

    public function test_dynamic_class_for_unmapped_table(): void
    {
        $this->db->schema()->create(ItUser::class);

        $object = $this->db->insert('it_user', ['id' => 3, 'username' => 'dyn']);
        self::assertSame('dyn', $object->username);

        $loaded = $this->db->get('it_user', 3);
        self::assertSame('dyn', $loaded->username);
    }

    public function test_two_nodes_read_the_same_data(): void
    {
        $this->db->schema()->create(ItLog::class);
        for ($i = 1; $i <= 50; $i++) {
            $this->db->insert(ItLog::class, ['id' => $i, 'msg' => "m{$i}"]);
        }

        $node2 = Picodata::connect(self::dsn(2));
        $count = $node2->query('SELECT COUNT(*) AS n FROM it_log')->all()[0]['n'];
        self::assertSame(50, (int) $count);

        $row = $node2->get(ItLog::class, 42);
        self::assertSame('m42', $row->msg);
    }

    public function test_vinyl_engine_roundtrip(): void
    {
        $this->db->schema()->create(ItLog::class);

        $this->db->insert(ItLog::class, ['id' => 1, 'msg' => str_repeat('x', 1024)]);
        self::assertSame(1024, strlen($this->db->get(ItLog::class, 1)->msg));
    }
}
