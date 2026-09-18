<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Driver;
use Basis\Picodata\Map\ClassFactory;
use Basis\Picodata\Map\Mapper;
use Basis\Picodata\Picodata;
use Basis\Picodata\Result;
use Basis\Picodata\Schema\Model;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        Mapper::reset();
        ClassFactory::forget();
    }

    private function model(): Model
    {
        return Model::define('orders')
            ->engine('vinyl')
            ->tier('hot')
            ->column('id', 'INTEGER', primary: true)
            ->column('username', 'TEXT')
            ->column('total', 'DOUBLE', nullable: true)
            ->index(['username'], unique: true);
    }

    /** @param list<array{rows?: list<array>, affected?: int}> $queue */
    private function db(array &$log, array $queue): Picodata
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

        return new Picodata($driver);
    }

    public function testToTableDerivesStructure(): void
    {
        $t = $this->model()->toTable();
        $columns = $t->columns;

        self::assertSame('orders', $t->name);
        self::assertSame(['id'], $t->primary, 'primary derived from the primary: flag');
        self::assertSame('vinyl', $t->engine);
        self::assertSame('hot', $t->tier);
        self::assertSame([], $t->distributed, 'empty means shard by the primary key');
        self::assertSame(['id', 'username', 'total'], array_keys($t->columns), 'map keeps declaration order');
        self::assertTrue($columns['id']->primary);
        self::assertTrue($columns['total']->nullable);

        self::assertCount(1, $t->indexes);
        self::assertTrue($t->indexes[0]->unique);
        self::assertSame(['username'], $t->indexes[0]->columns);
    }

    public function testPrimaryKeyCallOverridesDerivedKey(): void
    {
        $t = Model::define('t')
            ->column('a', 'INTEGER')
            ->column('b', 'INTEGER')
            ->primaryKey('b')
            ->toTable();
        $columns = $t->columns;

        self::assertSame(['b'], $t->primary);
        self::assertTrue($columns['b']->primary);
        self::assertFalse($columns['a']->primary);
    }

    public function testRegisterPutsTableInFactory(): void
    {
        self::assertSame('orders', $this->model()->register());
        $t = ClassFactory::tableFor('orders');

        self::assertNotNull($t);
        self::assertSame(['id'], $t->primary);
    }

    public function testCreateEmitsDdl(): void
    {
        $log = [];
        $db = $this->db($log, []);
        $db->schema()->create($this->model());

        $sql = implode("\n", $log);
        self::assertStringContainsString('CREATE TABLE orders', $sql);
        self::assertStringContainsString('PRIMARY KEY (id)', $sql);
        self::assertStringContainsString('USING vinyl', $sql);
        self::assertStringContainsString('IN TIER hot', $sql);
        self::assertStringContainsString('DISTRIBUTED BY (id)', $sql);
        self::assertStringContainsString('CREATE UNIQUE INDEX', $sql);
    }

    public function testFindThroughModelReturnsGeneratedTypedRows(): void
    {
        $log = [];
        $db = $this->db($log, [['rows' => [['id' => '7', 'username' => 'bob', 'total' => '3.5']]]]);

        $rows = $db->find($this->model());

        self::assertCount(1, $rows);
        self::assertStringStartsWith('Basis\\Picodata\\Runtime\\', $rows[0]::class);
        self::assertSame(7, $rows[0]->id);
        self::assertSame('bob', $rows[0]->username);
        self::assertSame(3.5, $rows[0]->total);
        self::assertNotNull(ClassFactory::tableFor('orders'), 'target normalization registered the table');
    }

    public function testInsertThroughModelEmitsInsertThenReload(): void
    {
        $log = [];
        $db = $this->db($log, [['affected' => 1], ['rows' => [['id' => '1', 'username' => 'x', 'total' => null]]]]);

        $obj = $db->insert($this->model(), ['id' => 1, 'username' => 'x', 'total' => null]);

        self::assertStringContainsString('INSERT INTO orders', $log[0]);
        self::assertSame(1, $obj->id);
        self::assertSame('x', $obj->username);
    }
}
