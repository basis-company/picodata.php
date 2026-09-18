<?php

declare(strict_types=1);

namespace Basis\Picodata\Test\Integration;

use Basis\Picodata\Attribute\TableName;
use Basis\Picodata\Change;
use Basis\Picodata\Picodata;

#[TableName('jt_user')]
class JtUser
{
    public function __construct(
        public int $id,
        public string $username,
        public ?bool $locked = null,
    ) {
    }
}

final class ClusterJournalTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropAll(['jt_user', 'jt_other', 'picodata_change', 'picodata_subscription']);
        $this->db->schema()->create(JtUser::class);
    }

    protected function tearDown(): void
    {
        $this->dropAll(['jt_user', 'jt_other', 'picodata_change', 'picodata_subscription']);
        parent::tearDown();
    }

    public function test_journal_roundtrip(): void
    {
        $this->db->insert(JtUser::class, ['id' => 1, 'username' => 'a']);

        $this->db->changes()->register('jt_user', 'itest');
        $this->db->changes()->setContext(['case' => __FUNCTION__]);

        $user = $this->db->get(JtUser::class, 1);
        $this->db->update($user, ['username' => 'b']);
        $this->db->delete(JtUser::class, 1);

        $changes = $this->db->changes()->get('itest');
        self::assertCount(2, $changes);
        self::assertContainsOnlyInstancesOf(Change::class, $changes);

        [$update, $delete] = $changes;
        self::assertSame('update', $update->action);
        self::assertSame('jt_user', $update->tablename);
        self::assertSame('b', $update->data['username']);
        self::assertSame(['case' => 'test_journal_roundtrip'], $update->context);
        self::assertNotNull($update->created_at);

        self::assertSame('delete', $delete->action);
        self::assertSame(1, $delete->data['id']);

        self::assertSame(2, $this->db->changes()->ack([$update->id, $delete->id]));
        self::assertSame([], $this->db->changes()->get('itest'));
    }

    public function test_write_in_throwing_transaction_still_journals(): void
    {
        $this->db->insert(JtUser::class, ['id' => 1, 'username' => 'a']);
        $this->db->changes()->register('jt_user', 'itest');

        // Picodata is autocommit-only and transaction() is a no-op, so the update
        // commits and is journaled before the throw; nothing rolls back. This pins
        // the real behaviour (and the exception propagating) instead of asserting
        // an atomicity the pgwire protocol does not provide.
        try {
            $this->db->transaction(function (Picodata $db): void {
                $db->update(JtUser::class, 1, ['username' => 'committed-anyway']);

                throw new \RuntimeException('rollback attempted');
            });
            self::fail('expected the exception to propagate');
        } catch (\RuntimeException) {
        }

        $changes = $this->db->changes()->get('itest');
        self::assertCount(1, $changes);
        self::assertSame('update', $changes[0]->action);
        self::assertSame('committed-anyway', $this->db->get(JtUser::class, 1)->username);
    }

    public function test_committed_transaction_and_journal_are_atomic(): void
    {
        $this->db->execute('CREATE TABLE jt_other (id INTEGER PRIMARY KEY, username TEXT) USING memtx');
        $this->db->insert(JtUser::class, ['id' => 1, 'username' => 'a']);
        $this->db->changes()->register('jt_user', 'itest');

        $this->db->transaction(function (Picodata $db): void {
            $db->update(JtUser::class, 1, ['username' => 'b']);
            $db->insert('jt_other', ['id' => 2, 'username' => 'x']);
        });

        // jt_other was never registered: only the jt_user change is journaled
        $changes = $this->db->changes()->get('itest');
        self::assertCount(1, $changes);
        self::assertSame('update', $changes[0]->action);
    }

    public function test_wildcard_subscription(): void
    {
        $this->db->execute('CREATE TABLE jt_other (id INTEGER PRIMARY KEY, username TEXT) USING memtx');

        $this->db->changes()->register('*', 'observer');
        $this->db->changes()->register('jt_*', 'glob-observer');
        $this->db->insert(JtUser::class, ['id' => 1, 'username' => 'a']);
        $this->db->insert('jt_other', ['id' => 1, 'username' => 'b']);

        $seen = array_column($this->db->changes()->get('observer'), 'tablename');
        sort($seen);
        self::assertSame(['jt_other', 'jt_user'], $seen);
        $seen = array_column($this->db->changes()->get('glob-observer'), 'tablename');
        sort($seen);
        self::assertSame(['jt_other', 'jt_user'], $seen);
    }

    public function test_journal_tables_created_lazily(): void
    {
        self::assertFalse($this->db->schema()->exists('picodata_change'));

        $this->db->changes()->register('jt_user', 'lazy');

        self::assertTrue($this->db->schema()->exists('picodata_change'));
        self::assertTrue($this->db->schema()->exists('picodata_subscription'));
    }
}
