<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Driver;
use Basis\Picodata\Map\ClassFactory;
use Basis\Picodata\Map\Mapper;
use Basis\Picodata\Result;
use Basis\Picodata\Schema\Column;
use Basis\Picodata\Schema\Model;
use Basis\Picodata\Schema\Ddl;
use Basis\Picodata\Schema\Index;
use Basis\Picodata\Schema\SchemaDiff;
use Basis\Picodata\Schema\SchemaManager;
use Basis\Picodata\Schema\Table;
use PHPUnit\Framework\TestCase;

final class SchemaMigrateTest extends TestCase
{
    protected function setUp(): void
    {
        Mapper::reset();
        ClassFactory::forget();
    }

    private function codeTable(): Table
    {
        return new Table(
            name: 'mg_user',
            columns: [
                new Column('id', 'INTEGER', primary: true),
                new Column('username', 'TEXT'),
                new Column('email', 'TEXT', nullable: true),
            ],
            primary: ['id'],
            indexes: [new Index(columns: ['email'])],
        );
    }

    /** The live shape: everything except the email column and its index. */
    private function liveTable(): Table
    {
        return new Table(
            name: 'mg_user',
            columns: [
                new Column('id', 'INTEGER', primary: true),
                new Column('username', 'TEXT'),
            ],
            primary: ['id'],
        );
    }

    public function testCompareFindsNewColumnAndIndex(): void
    {
        $diff = SchemaDiff::compare($this->codeTable(), $this->liveTable());

        self::assertFalse($diff->missingTable);
        self::assertSame(['email'], array_map(static fn (Column $c) => $c->name, $diff->addColumns));
        self::assertSame(
            ['mg_user_email_idx'],
            array_map(static fn (Index $i): string => (string) $i->name, $diff->addIndexes),
            'auto index names follow the same convention as Ddl',
        );
        self::assertSame([], $diff->issues);
        self::assertFalse($diff->isEmpty());
    }

    public function testCompareCurrentSchemaIsEmpty(): void
    {
        $diff = SchemaDiff::compare($this->liveTable(), $this->liveTable());

        self::assertTrue($diff->isEmpty());
    }

    public function testCompareReportsInplaceImpossibleDrift(): void
    {
        $live = new Table('mg_user', [
            new Column('id', 'INTEGER', primary: true),
            new Column('username', 'TEXT', nullable: true),   // code says NOT NULL
            new Column('legacy', 'TEXT', nullable: true),     // code dropped it
        ], ['id'], indexes: [new Index('mg_user_email_idx', ['username'])]);  // same name, other columns

        $diff = SchemaDiff::compare($this->codeTable(), $live);

        self::assertSame(['email'], array_map(static fn (Column $c) => $c->name, $diff->addColumns));
        self::assertSame([], $diff->addIndexes, 'the email index exists live, just with other columns');
        self::assertCount(3, $diff->issues, 'nullability, dropped column, differing index');
        self::assertStringContainsString('cannot change a column type', $diff->issues[0]);
        self::assertStringContainsString('cannot drop', $diff->issues[1]);
    }

    public function testCompareTreatsEmptyCodeDistributionAsByPrimaryKey(): void
    {
        $live = new Table('mg_user', [new Column('id', 'INTEGER', primary: true)], ['id'], distributed: ['id']);
        $code = new Table('mg_user', [new Column('id', 'INTEGER', primary: true)], ['id'], distributed: []);

        self::assertTrue(SchemaDiff::compare($code, $live)->isEmpty());
    }

    public function testAddColumnSql(): void
    {
        self::assertSame(
            'ALTER TABLE mg_user ADD COLUMN IF NOT EXISTS email TEXT',
            Ddl::addColumn(new Table('mg_user'), new Column('email', 'TEXT', nullable: true)),
        );
        self::assertSame(
            'ALTER TABLE mg_user ADD COLUMN IF NOT EXISTS hits INTEGER UNSIGNED NOT NULL',
            Ddl::addColumn(new Table('mg_user'), new Column('hits', 'INTEGER', unsigned: true)),
        );
    }

    /** @param list<array{rows?: list<array>, affected?: int}> $queue */
    private function manager(array &$log, array $queue): SchemaManager
    {
        $driver = new class ($log, $queue) implements Driver {
            /** @param list<string> $log @param list<array> $queue */
            public function __construct(private array &$log, private array $queue)
            {
            }

            public function statement(string $sql, array $params = []): Result
            {
                $this->log[] = $sql;
                $r = array_shift($this->queue) ?? [];

                return new Result($r['rows'] ?? [], $r['affected'] ?? 0);
            }

            public function driverName(): string
            {
                return 'fake';
            }
        };

        return new SchemaManager($driver);
    }

    /** Canned live introspection for mg_user without the email column. */
    private function liveQueue(int $extra): array
    {
        return array_merge([
            ['rows' => [['name' => 'mg_user']]],                                        // exists
            ['rows' => [[                                                             // _pico_table
                'name' => 'mg_user',
                'distribution' => json_encode(['ShardedImplicitly' => [['id'], null, 'default']]),
                'format' => json_encode([
                    ['name' => 'id', 'field_type' => 'integer', 'is_nullable' => false],
                    ['name' => 'username', 'field_type' => 'string', 'is_nullable' => false],
                ]),
                'engine' => 'memtx',
                'opts' => null,
            ]]],
            ['rows' => [[                                                             // _pico_index
                'id' => 0,
                'name' => 'mg_user_primary',
                'type' => 'TREE',
                'opts' => null,
                'parts' => json_encode([['field' => 'id']]),
            ]]],
        ], array_fill(0, $extra, ['rows' => []]));
    }

    public function testMigrateAppliesAddsAndRegisters(): void
    {
        $log = [];
        $schema = $this->manager($log, $this->liveQueue(2));

        $applied = $schema->migrate($this->codeTable());

        self::assertSame([
            'ALTER TABLE mg_user ADD COLUMN IF NOT EXISTS email TEXT',
            'CREATE INDEX mg_user_email_idx ON mg_user USING TREE (email)',
        ], $applied);
        self::assertSame($applied, array_slice($log, -2), 'the last two statements are the migration');
        self::assertNotNull(ClassFactory::tableFor('mg_user'));
    }

    public function testDropAcceptsModel(): void
    {
        $log = [];
        $schema = $this->manager($log, []);

        $schema->drop(Model::define('orders')->column('id', 'INTEGER', primary: true));

        self::assertSame('DROP TABLE IF EXISTS orders', end($log));
    }

    public function testDiffAgainstLiveReportsAdds(): void
    {
        $log = [];
        $schema = $this->manager($log, $this->liveQueue(0));

        $diff = $schema->diff($this->codeTable());

        self::assertFalse($diff->missingTable);
        self::assertSame(['email'], array_map(static fn (Column $c) => $c->name, $diff->addColumns));
    }

    public function testDiffAndMigrateOnMissingTable(): void
    {
        $log = [];
        $schema = $this->manager($log, [['rows' => []], ['rows' => []], ['rows' => []]]);

        self::assertTrue($schema->diff($this->codeTable())->missingTable);

        $applied = $schema->migrate($this->codeTable());
        self::assertStringStartsWith('CREATE TABLE mg_user', $applied[0]);
        self::assertCount(2, $applied, 'CREATE TABLE plus the index');
    }
}
