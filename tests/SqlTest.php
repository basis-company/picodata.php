<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testSelectBasic(): void
    {
        $q = Sql::select('auth.user', ['id', 'name'], ['id' => 5]);

        $this->assertSame('SELECT id,name FROM auth.user WHERE id = ?', $q->sql);
        $this->assertSame([5], $q->params);
    }

    public function testSelectQuirksQuoting(): void
    {
        $q = Sql::select('MyTable', ['NameX'], ['Order' => 1]);

        $this->assertSame('SELECT "NameX" FROM "MyTable" WHERE "Order" = ?', $q->sql);
        $this->assertSame([1], $q->params);
    }

    public function testSelectAllColumnsNoWhere(): void
    {
        $q = Sql::select('user');

        $this->assertSame('SELECT * FROM user', $q->sql);
        $this->assertSame([], $q->params);
    }

    public function testSelectWhereIsNull(): void
    {
        $q = Sql::select('user', ['id'], ['deleted_at' => null]);

        $this->assertSame('SELECT id FROM user WHERE deleted_at IS NULL', $q->sql);
        $this->assertSame([], $q->params);
    }

    public function testSelectWhereIn(): void
    {
        $q = Sql::select('user', ['id'], ['id' => [1, 2, 3], 'active' => true]);

        $this->assertSame('SELECT id FROM user WHERE id IN (?,?,?) AND active = ?', $q->sql);
        $this->assertSame([1, 2, 3, true], $q->params);
    }

    public function testSelectWhereOperators(): void
    {
        $q = Sql::select('user', ['id'], ['age' => ['>=' => 18, '<' => 65], 'name' => ['LIKE' => 'a%']]);

        $this->assertSame(
            'SELECT id FROM user WHERE age >= ? AND age < ? AND name LIKE ?',
            $q->sql
        );
        $this->assertSame([18, 65, 'a%'], $q->params);
    }

    public function testSelectWhereEmptyIsTrue(): void
    {
        $params = [];

        $this->assertSame('1', Sql::where([], $params));
        $this->assertSame('UPDATE t SET a = ? WHERE 1', Sql::update('t', ['a' => 1], [])->sql);
    }

    public function testSelectOrderVariants(): void
    {
        $this->assertSame(
            'SELECT * FROM t ORDER BY id DESC',
            Sql::select('t', ['*'], [], ['order' => 'id DESC'])->sql
        );
        $this->assertSame(
            'SELECT * FROM t ORDER BY id DESC,name',
            Sql::select('t', ['*'], [], ['order' => 'id DESC, name'])->sql
        );
        $this->assertSame(
            'SELECT * FROM t ORDER BY id,name',
            Sql::select('t', ['*'], [], ['order' => ['id', 'name']])->sql
        );
        $this->assertSame(
            'SELECT * FROM t ORDER BY id DESC,name ASC',
            Sql::select('t', ['*'], [], ['order' => ['id' => 'DESC', 'name' => 'ASC']])->sql
        );
    }

    public function testSelectLimitOffset(): void
    {
        $q = Sql::select('auth.user', ['id'], ['x' => 1], ['order' => 'id DESC', 'limit' => 10, 'offset' => 5]);

        $this->assertSame('SELECT id FROM auth.user WHERE x = ? ORDER BY id DESC LIMIT 10 OFFSET 5', $q->sql);
        $this->assertSame([1], $q->params);
    }

    public function testInsert(): void
    {
        $q = Sql::insert('auth.user', ['id' => 1, 'name' => 'neo']);

        $this->assertSame('INSERT INTO auth.user (id,name) VALUES (?,?)', $q->sql);
        $this->assertSame([1, 'neo'], $q->params);
    }

    public function testInsertOnConflict(): void
    {
        $q = Sql::insert('user', ['id' => 1], 'ON CONFLICT DO NOTHING');

        $this->assertSame('INSERT INTO user (id) VALUES (?) ON CONFLICT DO NOTHING', $q->sql);
        $this->assertSame([1], $q->params);
    }

    public function testUpdateParamsOrder(): void
    {
        $q = Sql::update('user', ['name' => 'bob', 'age' => 30], ['id' => 7, 'active' => true]);

        $this->assertSame('UPDATE user SET name = ?, age = ? WHERE id = ? AND active = ?', $q->sql);
        $this->assertSame(['bob', 30, 7, true], $q->params);
    }

    public function testDelete(): void
    {
        $q = Sql::delete('user', ['id' => [4, 5]]);

        $this->assertSame('DELETE FROM user WHERE id IN (?,?)', $q->sql);
        $this->assertSame([4, 5], $q->params);
    }

    public function testUpdateEmptyChangesThrows(): void
    {
        $this->expectException(InvalidException::class);
        Sql::update('user', [], ['id' => 1]);
    }

    public function testInsertEmptyValuesThrows(): void
    {
        $this->expectException(InvalidException::class);
        Sql::insert('user', []);
    }

    public function testBadOperatorThrows(): void
    {
        $this->expectException(InvalidException::class);
        Sql::select('t', ['*'], ['id' => ['~' => 1]]);
    }

    public function testEmptyInListThrows(): void
    {
        $this->expectException(InvalidException::class);
        Sql::delete('t', ['id' => []]);
    }

    public function testBadOnConflictThrows(): void
    {
        $this->expectException(InvalidException::class);
        Sql::insert('t', ['id' => 1], 'RETURNING *');
    }

    public function testNonIntLimitThrows(): void
    {
        $this->expectException(InvalidException::class);
        Sql::select('t', ['*'], [], ['limit' => '10']);
    }

    public function testNegativeOffsetThrows(): void
    {
        $this->expectException(InvalidException::class);
        Sql::select('t', ['*'], [], ['offset' => -1]);
    }

    public function testBadOrderDirectionThrows(): void
    {
        $this->expectException(InvalidException::class);
        Sql::select('t', ['*'], [], ['order' => ['id' => 'RANDOM']]);
    }

    public function testBadIdentifierThrows(): void
    {
        $this->expectException(InvalidException::class);
        Sql::select('t', ['*'], ['id;DROP TABLE x' => 1]);
    }
}
