<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Attribute\TableName;
use Basis\Picodata\Driver;
use Basis\Picodata\Picodata;
use Basis\Picodata\Result;
use PHPUnit\Framework\TestCase;

#[TableName('chg_user')]
class ChgUser
{
    public function __construct(
        public int $id,
        public string $username,
    ) {
    }
}

final class ChangesTest extends TestCase
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

    /**
     * register() on a virgin database: 2 existence SELECTs, 4 DDLs,
     * 1 subscription INSERT — 7 driver calls.
     */
    private function registerQueue(): array
    {
        return array_fill(0, 7, ['rows' => []]);
    }

    public function test_write_with_subscriber_is_journaled_atomically(): void
    {
        $db = $this->db(array_merge($this->registerQueue(), [
            ['rows' => [['listener' => 'worker', 'tablename' => 'chg_user']]], // listeners SELECT
            ['rows' => []],                                                    // BEGIN
            ['affected' => 1],                                                 // UPDATE
            ['rows' => [['id' => '1', 'username' => 'b']]],                    // after-image SELECT
            ['rows' => []],                                                    // journal INSERT
            ['rows' => []],                                                    // COMMIT
        ]));

        $db->changes()->register('chg_user', 'worker');
        $db->update(ChgUser::class, 1, ['username' => 'b']);

        $order = array_column($this->log, 'sql');
        self::assertStringStartsWith('INSERT INTO picodata_subscription', $order[6]);
        self::assertStringStartsWith('UPDATE chg_user', $order[9]);
        self::assertStringStartsWith('SELECT', $order[10]);
        self::assertStringStartsWith('INSERT INTO picodata_change', $order[11]);
        self::assertSame('COMMIT', $order[12]);

        $journal = $this->log[11];
        self::assertSame('worker', $journal['params'][1]);
        self::assertSame('chg_user', $journal['params'][2]);
        self::assertSame('update', $journal['params'][3]);
        self::assertJsonStringEqualsJsonString('{"id":1,"username":"b"}', $journal['params'][4]);
    }

    public function test_no_subscribers_means_plain_write(): void
    {
        $db = $this->db(array_merge($this->registerQueue(), [
            ['rows' => []],        // listeners SELECT: nobody watches chg_user
            ['affected' => 1],     // plain UPDATE, no transaction
        ]));

        $db->changes()->register('other_table', 'worker');
        $db->update(ChgUser::class, 1, ['username' => 'b']);

        foreach ($this->log as $entry) {
            self::assertStringNotContainsString('INSERT INTO picodata_change', $entry['sql']);
            self::assertNotSame('BEGIN', $entry['sql']);
            self::assertNotSame('COMMIT', $entry['sql']);
        }
    }

    public function test_never_touched_journal_has_zero_overhead(): void
    {
        $db = $this->db([['affected' => 1]]);

        $db->update(ChgUser::class, 1, ['username' => 'b']);

        self::assertCount(1, $this->log);
        self::assertStringStartsWith('UPDATE chg_user', $this->log[0]['sql']);
    }

    public function test_transaction_adds_no_sql_around_journal(): void
    {
        $db = $this->db(array_merge($this->registerQueue(), [
            ['rows' => [['listener' => 'worker', 'tablename' => 'chg_user']]], // listeners SELECT
            ['rows' => []],                                                    // BEGIN (from Changes)
            ['affected' => 1],                                                 // UPDATE
            ['rows' => [['id' => '1', 'username' => 'b']]],                    // after-image SELECT
            ['rows' => []],                                                    // journal INSERT
            ['rows' => []],                                                    // COMMIT
        ]));

        $db->changes()->register('chg_user', 'worker');
        $db->transaction(function (Picodata $db): void {
            $db->update(ChgUser::class, 1, ['username' => 'b']);
        });

        // Picodata::transaction() is a no-op (autocommit-only pgwire), so wrapping a
        // journaled write in it emits no extra BEGIN/COMMIT: journaling still wraps the
        // write exactly once, producing the same log as a standalone journaled update.
        $order = array_column($this->log, 'sql');
        self::assertSame(1, \count(array_filter($order, static fn (string $s): bool => $s === 'BEGIN')));
        self::assertStringStartsWith('UPDATE chg_user', $order[9]);
        self::assertStringStartsWith('INSERT INTO picodata_change', $order[11]);
        self::assertSame('COMMIT', $order[12]);
    }

    public function test_failure_rolls_back_and_skips_journal(): void
    {
        $db = $this->db(array_merge($this->registerQueue(), [
            ['rows' => []], // BEGIN
            ['rows' => []], // ROLLBACK
        ]));

        $db->changes()->register('chg_user', 'worker');

        try {
            $db->changes()->guard('chg_user', function (): array {
                throw new \RuntimeException('boom');
            });
            self::fail('exception expected');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $order = array_column($this->log, 'sql');
        self::assertSame('BEGIN', $order[7]);
        self::assertSame('ROLLBACK', $order[8]);
        self::assertStringNotContainsString('INSERT INTO picodata_change', implode("\n", $order));
    }

    public function test_get_and_ack_drain_queue(): void
    {
        $db = $this->db([
            ['rows' => [['name' => 'picodata_change']]],
            ['rows' => [['name' => 'picodata_subscription']]],
            ['rows' => [[
                'id' => 'c1',
                'listener' => 'worker',
                'tablename' => 'chg_user',
                'action' => 'insert',
                'data' => '{"id":1,"username":"a"}',
                'context' => '{}',
                'created_at' => '2026-09-17 10:00:00',
            ]]],
            ['affected' => 1],
        ]);

        $changes = $db->changes()->get('worker', limit: 10);

        self::assertCount(1, $changes);
        self::assertSame('chg_user', $changes[0]->tablename);
        self::assertSame(['id' => 1, 'username' => 'a'], $changes[0]->data);
        self::assertInstanceOf(\DateTimeImmutable::class, $changes[0]->created_at);
        self::assertSame(1, $db->changes()->ack(['c1']));

        self::assertStringStartsWith('DELETE FROM picodata_change WHERE id IN', $this->log[3]['sql']);
    }

    public function test_wildcard_subscription_journals_every_table(): void
    {
        $db = $this->db(array_merge($this->registerQueue(), [
            ['rows' => [['listener' => 'audit', 'tablename' => '*']]],  // listeners SELECT
            ['rows' => []],                                             // BEGIN
            ['affected' => 1],                                          // UPDATE
            ['rows' => [['id' => '1', 'username' => 'b']]],             // after-image SELECT
            ['rows' => []],                                             // journal INSERT
            ['rows' => []],                                             // COMMIT
        ]));

        $db->changes()->register('*', 'audit');
        $db->update(ChgUser::class, 1, ['username' => 'b']);

        $journal = $this->journalEntry();
        self::assertSame('audit', $journal['params'][1]);
        self::assertSame('chg_user', $journal['params'][2], 'the journal row names the changed table');
        self::assertSame('update', $journal['params'][3]);
    }

    public function test_glob_subscription_journals_matching_tables(): void
    {
        $db = $this->db(array_merge($this->registerQueue(), [
            ['rows' => [['listener' => 'audit', 'tablename' => 'chg_*']]],
            ['rows' => []],
            ['affected' => 1],
            ['rows' => [['id' => '1', 'username' => 'b']]],
            ['rows' => []],
            ['rows' => []],
        ]));

        $db->changes()->register('chg_*', 'audit');
        $db->update(ChgUser::class, 1, ['username' => 'b']);

        self::assertSame('audit', $this->journalEntry()['params'][1]);
    }

    public function test_glob_subscription_skips_unmatching_tables(): void
    {
        $db = $this->db(array_merge($this->registerQueue(), [
            ['rows' => [['listener' => 'audit', 'tablename' => 'usr_*']]],
            ['affected' => 1],
        ]));

        $db->changes()->register('usr_*', 'audit');
        $db->update(ChgUser::class, 1, ['username' => 'b']);

        self::assertCount(0, array_filter($this->log, static fn (array $e): bool => str_starts_with($e['sql'], 'INSERT INTO picodata_change')));
    }

    private function journalEntry(): array
    {
        foreach ($this->log as $entry) {
            if (str_starts_with($entry['sql'], 'INSERT INTO picodata_change')) {
                return $entry;
            }
        }

        self::fail('no journal INSERT emitted');
    }
}
