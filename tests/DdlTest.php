<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Driver;
use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Exception\NotFoundException;
use Basis\Picodata\Result;
use Basis\Picodata\Schema\Column;
use Basis\Picodata\Schema\Ddl;
use Basis\Picodata\Schema\Expression;
use Basis\Picodata\Schema\Index;
use Basis\Picodata\Schema\SchemaManager;
use Basis\Picodata\Schema\Table;
use PHPUnit\Framework\TestCase;

/**
 * Driver stub: canned rows keyed by a SQL substring.
 */
final class FakeDriver implements Driver
{
    /** @param array<string, array[]> $maps substring => rows */
    public function __construct(
        private readonly array $maps = [],
    ) {
    }

    public function statement(string $sql, array $params = []): Result
    {
        foreach ($this->maps as $needle => $rows) {
            if (str_contains($sql, $needle)) {
                return new Result($rows);
            }
        }

        return new Result([]);
    }

    public function driverName(): string
    {
        return 'fake';
    }
}

final class DdlTest extends TestCase
{
    public function testShardedMemtxFullClause(): void
    {
        $t = new Table(
            'auth_user',
            [
                new Column('id', 'INTEGER'),
                new Column('name', 'TEXT'),
                new Column('tags', 'TEXT', nullable: true, array: true),
                new Column('n', 'INTEGER', nullable: true, unsigned: true),
            ],
            primary: ['id'],
            engine: 'memtx',
            distributed: ['id'],
            tier: 'hot',
            timeout: 3.0,
            waitApplied: true,
        );
        $this->assertSame(
            'CREATE TABLE auth_user (id INTEGER NOT NULL, name TEXT NOT NULL, '
            . 'tags TEXT ARRAY, n INTEGER UNSIGNED, PRIMARY KEY (id)) '
            . 'USING memtx DISTRIBUTED BY (id) IN TIER hot '
            . 'WAIT APPLIED GLOBALLY OPTION (TIMEOUT = 3.0)',
            Ddl::createTable($t),
        );
    }

    public function testGlobalTable(): void
    {
        $t = new Table('dict', [new Column('code', 'TEXT')], primary: ['code'], distributed: null);
        $this->assertSame(
            'CREATE TABLE dict (code TEXT NOT NULL, PRIMARY KEY (code)) '
            . 'USING memtx DISTRIBUTED GLOBALLY',
            Ddl::createTable($t),
        );
    }

    public function testUnlogged(): void
    {
        $t = new Table('tmp', [new Column('id', 'INTEGER')], primary: ['id'], unlogged: true);
        $this->assertSame(
            'CREATE UNLOGGED TABLE tmp (id INTEGER NOT NULL, PRIMARY KEY (id)) '
            . 'USING memtx DISTRIBUTED BY (id)',
            Ddl::createTable($t),
        );
    }

    public function testVinylEngine(): void
    {
        $t = new Table('events', [new Column('id', 'INTEGER')], primary: ['id'], engine: 'vinyl');
        $this->assertSame(
            'CREATE TABLE events (id INTEGER NOT NULL, PRIMARY KEY (id)) '
            . 'USING vinyl DISTRIBUTED BY (id)',
            Ddl::createTable($t),
        );
    }

    public function testDefaultsAndTypes(): void
    {
        $t = new Table('t', [
            new Column('id', 'INTEGER'),
            new Column('x', 'TEXT', nullable: true, default: "g'uest"),
            new Column('ts', 'DATETIME', nullable: true, default: new Expression('CURRENT_TIMESTAMP')),
            new Column('flags', 'BOOLEAN', nullable: true, default: false),
        ], primary: ['id']);
        $this->assertSame(
            "CREATE TABLE t (id INTEGER NOT NULL, x TEXT DEFAULT 'g''uest', "
            . 'ts DATETIME DEFAULT CURRENT_TIMESTAMP, flags BOOLEAN DEFAULT FALSE, '
            . 'PRIMARY KEY (id)) USING memtx DISTRIBUTED BY (id)',
            Ddl::createTable($t),
        );
    }

    public function testCompositePrimaryFallback(): void
    {
        $t = new Table('t', [new Column('a', 'INTEGER'), new Column('b', 'INTEGER')], primary: ['a', 'b']);
        $this->assertSame(
            'CREATE TABLE t (a INTEGER NOT NULL, b INTEGER NOT NULL, PRIMARY KEY (a, b)) '
            . 'USING memtx DISTRIBUTED BY (a, b)',
            Ddl::createTable($t),
        );
    }

    public function testCreateIndex(): void
    {
        $t = new Table('t');
        $this->assertSame(
            'CREATE UNIQUE INDEX t_name ON t USING TREE (a, b DESC)',
            Ddl::createIndex($t, new Index('t_name', ['a', ['b', 'DESC']], 'TREE', true)),
        );
        $this->assertSame(
            'CREATE INDEX t_hash ON t USING HASH (c)',
            Ddl::createIndex($t, new Index('t_hash', ['c'], 'HASH')),
        );
    }

    public function testCreateIndexAutoName(): void
    {
        $t = new Table('auth_user');
        $this->assertSame(
            'CREATE INDEX auth_user_name_idx ON auth_user USING TREE (name)',
            Ddl::createIndex($t, new Index(columns: ['name'])),
        );
        $this->assertSame(
            'CREATE UNIQUE INDEX auth_user_tenant_name_idx ON auth_user USING TREE (tenant, name DESC)',
            Ddl::createIndex($t, new Index(columns: ['tenant', ['name', 'DESC']], unique: true)),
        );
    }

    public function testDrop(): void
    {
        $this->assertSame('DROP TABLE IF EXISTS t', Ddl::dropTable('t'));
        $this->assertSame('DROP TABLE IF EXISTS t', Ddl::dropTable(new Table('t')));
        $this->assertSame('DROP INDEX IF EXISTS t_name', Ddl::dropIndex('t_name'));
    }

    public function testGlobalVinylRejected(): void
    {
        $this->expectException(InvalidException::class);
        Ddl::createTable(new Table('t', [new Column('id', 'INTEGER')], engine: 'vinyl', distributed: null));
    }

    public function testShardedWithoutKeyRejected(): void
    {
        $this->expectException(InvalidException::class);
        Ddl::createTable(new Table('t', [new Column('id', 'INTEGER')]));
    }

    public function testQuoting(): void
    {
        $t = new Table('MyTable', [new Column('my-col', 'INTEGER')], primary: ['my-col']);
        $this->assertSame(
            'CREATE TABLE "MyTable" ("my-col" INTEGER NOT NULL, PRIMARY KEY ("my-col")) '
            . 'USING memtx DISTRIBUTED BY ("my-col")',
            Ddl::createTable($t),
        );
    }

    public function testSchemaManagerLoad(): void
    {
        $driver = new FakeDriver([
            'FROM _pico_index' => [
                ['id' => 0, 'name' => 'pk', 'type' => 'TREE', 'opts' => '[{"unique":true}]',
                    'parts' => '[{"field":"id"}]'],
                ['id' => 1, 'name' => 'idx_name', 'type' => 'TREE', 'opts' => '[{"unique":false}]',
                    'parts' => '["name"]'],
            ],
            'FROM _pico_table' => [[
                'name' => 'user',
                'distribution' => '{"ShardedByField":["id",null]}',
                'format' => '[{"name":"id","field_type":"unsigned","is_nullable":false},'
                    . '{"name":"name","field_type":"string","is_nullable":true},'
                    . '{"name":"tags","field_type":"array","is_nullable":true},'
                    . '{"name":"ts","field_type":"datetime","is_nullable":true}]',
                'engine' => 'memtx',
                'opts' => '{}',
            ]],
        ]);
        $manager = new SchemaManager($driver);

        $this->assertTrue($manager->exists('user'));
        $t = $manager->load('user');

        $this->assertSame('user', $t->name);
        $this->assertSame('memtx', $t->engine);
        $this->assertSame(['id'], $t->primary);
        $this->assertSame(['id'], $t->distributed);
        $this->assertNull($t->tier);
        $columns = $t->columns;
        $this->assertCount(4, $columns);
        $this->assertTrue($columns['id']->unsigned);
        $this->assertTrue($columns['id']->primary);
        $this->assertSame('INTEGER', $columns['id']->type);
        $this->assertSame('TEXT', $columns['name']->type);
        $this->assertTrue($columns['name']->nullable);
        $this->assertTrue($columns['tags']->array);
        $this->assertSame('DATETIME', $columns['ts']->type);
        $this->assertSame('?int', $t->phpType('id'));
        $this->assertSame('string', $t->phpType('name'));
        $this->assertNull($t->phpType('nope'));
        $this->assertCount(1, $t->indexes);
        $this->assertSame('idx_name', $t->indexes[0]->name);
        $this->assertFalse($t->indexes[0]->unique);
        $this->assertSame(['name'], $t->indexes[0]->columns);
    }

    public function testSchemaManagerLoadGlobalAndBucketId(): void
    {
        $driver = new FakeDriver([
            'FROM _pico_index' => [
                ['id' => 0, 'name' => 'pk', 'type' => 'TREE', 'opts' => null,
                    'parts' => ['bucket_id', ['field' => 'id']]],
            ],
            'FROM _pico_table' => [[
                'name' => 'g',
                'distribution' => '{"Global":null}',
                'format' => [['name' => 'bucket_id', 'field_type' => 'integer', 'is_nullable' => false],
                    ['name' => 'id', 'field_type' => 'integer', 'is_nullable' => false]],
                'engine' => 'memtx',
                'opts' => '{"pk_contains_bucket_id":[true]}',
            ]],
        ]);
        $t = (new SchemaManager($driver))->load('g');

        $this->assertNull($t->distributed);
        $this->assertSame(['id'], $t->primary);
    }

    public function testSchemaManagerMissingTable(): void
    {
        $manager = new SchemaManager(new FakeDriver());
        $this->assertFalse($manager->exists('nope'));
        $this->expectException(NotFoundException::class);
        $manager->load('nope');
    }
}
