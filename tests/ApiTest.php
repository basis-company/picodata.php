<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Attribute\TableName;
use Basis\Picodata\Attribute\Tier;
use Basis\Picodata\Driver;
use Basis\Picodata\Exception\NotFoundException;
use Basis\Picodata\Map\Mapper;
use Basis\Picodata\Picodata;
use Basis\Picodata\Result;
use PHPUnit\Framework\TestCase;

#[TableName('auth_user')]
#[Tier('hot')]
class ApiUser
{
    public function __construct(
        public int $id,
        public string $username,
        public ?bool $active = null,
    ) {
    }
}

#[TableName('auth_user')]
#[Tier('hot')]
class ApiNullableUser
{
    public function __construct(
        public ?int $id,
        public string $username,
        public ?bool $active = null,
    ) {
    }
}

final class ApiTest extends TestCase
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
                $this->log[] = ['sql' => $sql, 'params' => $params];
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

    public function test_find_returns_typed_objects(): void
    {
        $db = $this->db([['rows' => [
            ['id' => '1', 'username' => 'nekufa', 'active' => 't'],
            ['id' => '2', 'username' => 'guest', 'active' => 'f'],
        ]]]);

        $users = $db->find(ApiUser::class, ['active' => 1]);

        self::assertSame(ApiUser::class, $users[0]::class);
        self::assertSame(1, $users[0]->id);
        self::assertTrue($users[0]->active);
        self::assertSame(2, $users[1]->id);
        self::assertFalse($users[1]->active);

        self::assertStringContainsString('FROM auth_user', $this->log[0]['sql']);
        self::assertStringContainsString('WHERE active = ?', $this->log[0]['sql']);
        self::assertStringContainsString('SELECT id,username,active', $this->log[0]['sql']);
        self::assertSame([1], $this->log[0]['params']);
    }

    public function test_find_one_and_fail(): void
    {
        $db = $this->db([['rows' => [['id' => '7', 'username' => 'nekufa', 'active' => null]]]]);
        $user = $db->findOne(ApiUser::class, ['username' => 'nekufa']);
        self::assertInstanceOf(ApiUser::class, $user);
        self::assertStringContainsString('LIMIT 1', $this->log[0]['sql']);

        $db = $this->db([['rows' => []]]);
        $this->log = [];
        $this->expectException(NotFoundException::class);
        $db->findOrFail('auth_user', ['username' => 'guest']);
    }

    public function test_find_by_plain_table_name_introspects_and_generates_class(): void
    {
        $format = json_encode([
            ['name' => 'id', 'field_type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'username', 'field_type' => 'string', 'is_nullable' => false],
        ]);
        $distribution = json_encode(['ShardedImplicitly' => [['id'], 'murmur3', 'default']]);

        $db = $this->db([
            ['rows' => [['name' => 'user', 'distribution' => $distribution, 'format' => $format, 'engine' => 'memtx', 'opts' => '[]']]], // _pico_table
            ['rows' => []],                                                // _pico_index
            ['rows' => [['id' => 3, 'username' => 'nekufa']]],             // find
        ]);

        $user = $db->findOrFail('user', ['username' => 'nekufa']);

        self::assertSame(3, $user->id);
        self::assertSame('nekufa', $user->username);
        self::assertStringContainsString('SELECT id,username FROM user', $this->log[2]['sql']);
    }

    public function test_find_or_create_found_path(): void
    {
        $db = $this->db([['rows' => [['id' => '5', 'username' => 'nekufa', 'active' => null]]]]);

        $user = $db->findOrCreate(ApiUser::class, ['username' => 'nekufa']);

        self::assertSame('nekufa', $user->username);
        self::assertCount(1, $this->log); // no insert
    }

    public function test_find_or_create_creates_then_refinds(): void
    {
        $db = $this->db([
            ['rows' => []],                                                            // findOne -> miss
            ['affected' => 1],                                                          // INSERT
            ['rows' => [['id' => '9', 'username' => 'nekufa', 'active' => 't']]],       // refind
        ]);

        $user = $db->findOrCreate(ApiUser::class, ['username' => 'nekufa'], ['id' => 9, 'active' => true]);

        self::assertSame(9, $user->id);
        self::assertStringContainsString('INSERT INTO auth_user (username,id,active) VALUES (?,?,?)', $this->log[1]['sql']);
        self::assertStringContainsString('ON CONFLICT DO NOTHING', $this->log[1]['sql']);
        self::assertSame(['nekufa', 9, true], $this->log[1]['params']);
    }

    public function test_update_by_key(): void
    {
        $db = $this->db([['affected' => 1]]);

        $affected = $db->update(ApiUser::class, 42, ['username' => 'neku']);

        self::assertSame(1, $affected);
        self::assertSame('UPDATE auth_user SET username = ? WHERE id = ?', $this->log[0]['sql']);
        self::assertSame(['neku', 42], $this->log[0]['params']);
    }

    public function test_update_object_target_updates_in_memory(): void
    {
        $db = $this->db([['affected' => 1]]);
        $user = new ApiUser(id: 42, username: 'old', active: true);

        $db->update($user, 42, ['username' => 'new']);

        self::assertSame('new', $user->username);
        self::assertStringContainsString('WHERE id = ?', $this->log[0]['sql']);
    }

    public function test_delete_by_key(): void
    {
        $db = $this->db([['affected' => 1]]);

        self::assertSame(1, $db->delete(ApiUser::class, 42));
        self::assertSame('DELETE FROM auth_user WHERE id = ?', $this->log[0]['sql']);
    }

    public function test_save_updates_when_pk_present(): void
    {
        $db = $this->db([['affected' => 1]]);
        $user = new ApiUser(id: 1, username: 'a', active: false);

        $db->save($user);

        self::assertSame('UPDATE auth_user SET username = ?, active = ? WHERE id = ?', $this->log[0]['sql']);
        self::assertSame(['a', false, 1], $this->log[0]['params']);
    }

    public function test_save_inserts_and_refetches_when_no_pk(): void
    {
        $db = $this->db([
            ['affected' => 1],
            ['rows' => [['id' => '10', 'username' => 'fresh', 'active' => null]]],
        ]);
        $user = new ApiNullableUser(id: null, username: 'fresh');

        $saved = $db->save($user);

        self::assertSame(10, $saved->id);
        self::assertStringContainsString('INSERT INTO auth_user (id,username,active) VALUES (?,?,?)', $this->log[0]['sql']);
        self::assertStringContainsString('WHERE id IS NULL AND username = ? AND active IS NULL', $this->log[1]['sql']);
    }

    public function test_raw_query_and_execute_pass_through(): void
    {
        $db = $this->db([
            ['rows' => [['a' => 1]], 'affected' => 0],
            ['affected' => 3],
        ]);

        self::assertSame([['a' => 1]], $db->query('SELECT 1 AS a')->all());
        self::assertSame(3, $db->execute('DELETE FROM x WHERE a = ?', [1]));
        self::assertSame('SELECT 1 AS a', $this->log[0]['sql']);
        self::assertSame('DELETE FROM x WHERE a = ?', $this->log[1]['sql']);
        self::assertSame([1], $this->log[1]['params']);
    }

    public function test_transaction_is_noop_and_propagates(): void
    {
        $db = $this->db([]);

        // Picodata's pgwire is autocommit-only, so transaction() is a no-op: it runs
        // the callable, returns its value, and issues no BEGIN/COMMIT/SAVEPOINT SQL.
        self::assertSame('ok', $db->transaction(fn () => 'ok'));
        self::assertSame([], $this->log);

        try {
            $db->transaction(function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail();
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame([], $this->log);
    }

    public function test_find_options_order_limit_offset(): void
    {
        $db = $this->db([['rows' => []]]);

        $db->find(ApiUser::class, ['age' => ['>=' => 18]], ['order' => ['id' => 'DESC'], 'limit' => 10, 'offset' => 5]);

        self::assertStringContainsString('WHERE age >= ?', $this->log[0]['sql']);
        self::assertStringContainsString('ORDER BY id DESC', $this->log[0]['sql']);
        self::assertStringContainsString('LIMIT 10 OFFSET 5', $this->log[0]['sql']);
        self::assertSame([18], $this->log[0]['params']);
    }

    public function test_where_in_and_null_forms(): void
    {
        $db = $this->db([['rows' => []]]);

        $db->find(ApiUser::class, ['id' => [1, 2, 3], 'active' => null]);

        self::assertStringContainsString('WHERE id IN (?,?,?) AND active IS NULL', $this->log[0]['sql']);
        self::assertSame([1, 2, 3], $this->log[0]['params']);
    }
}
