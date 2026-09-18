<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Quoter;
use Basis\Picodata\Result;
use PHPUnit\Framework\TestCase;

final class QuoterTest extends TestCase
{
    public function testIdentifierBare(): void
    {
        self::assertSame('users', Quoter::identifier('users'));
        self::assertSame('_order2', Quoter::identifier('_order2'));
    }

    public function testIdentifierQuoted(): void
    {
        self::assertSame('"Users"', Quoter::identifier('Users'));
        self::assertSame('"user name"', Quoter::identifier('user name'));
        self::assertSame('"a""b"', Quoter::identifier('a"b'));
        self::assertSame('"1x"', Quoter::identifier('1x'));
    }

    public function testIdentifierDotted(): void
    {
        self::assertSame('auth.user', Quoter::identifier('auth.user'));
        self::assertSame('auth."User"', Quoter::identifier('auth.User'));
    }

    public function testList(): void
    {
        self::assertSame('a, "B", c', Quoter::list(['a', 'B', 'c']));
    }

    public function testPositionalSkipsQuotedParts(): void
    {
        [$sql, $params] = Quoter::positional(
            "SELECT ? FROM \"we?rd\" WHERE a = '?' AND b = ?",
            ['x', 42],
        );

        self::assertSame('SELECT $1 FROM "we?rd" WHERE a = \'?\' AND b = $2', $sql);
        self::assertSame(['x', 42], $params);
    }

    public function testPositionalKeepsCast(): void
    {
        [$sql, $params] = Quoter::positional('SELECT x::text WHERE a = ?', [1]);

        self::assertSame('SELECT x::text WHERE a = $1', $sql);
        self::assertSame([1], $params);
    }

    public function testPositionalTooManyParams(): void
    {
        $this->expectException(InvalidException::class);
        Quoter::positional('SELECT ?', [1, 2]);
    }

    public function testPositionalMissingParam(): void
    {
        $this->expectException(InvalidException::class);
        Quoter::positional('SELECT ? , ? , ?', [1]);
    }

    public function testNamedParams(): void
    {
        [$sql, $params] = Quoter::positional(
            'SELECT * FROM t WHERE a = :a AND b = :b AND a2 = :a',
            ['b' => 2, 'a' => 1],
        );

        self::assertSame('SELECT * FROM t WHERE a = $1 AND b = $2 AND a2 = $1', $sql);
        self::assertSame([1, 2], $params);
    }

    public function testNamedMissingParam(): void
    {
        $this->expectException(InvalidException::class);
        Quoter::positional('WHERE a = :a', ['b' => 1]);
    }

    public function testLiteral(): void
    {
        self::assertSame('NULL', Quoter::literal(null));
        self::assertSame('TRUE', Quoter::literal(true));
        self::assertSame('FALSE', Quoter::literal(false));
        self::assertSame('42', Quoter::literal(42));
        self::assertSame("'it''s'", Quoter::literal("it's"));
        self::assertSame(
            "'2026-09-17 10:20:30'",
            Quoter::literal(new \DateTimeImmutable('2026-09-17 10:20:30')),
        );
    }

    public function testResultBasics(): void
    {
        $result = new Result([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ], 2);

        self::assertSame(['id' => 1, 'name' => 'a'], $result->first());
        self::assertSame(['a', 'b'], $result->column('name'));
        self::assertSame([null, null], $result->column('missing'));
        self::assertSame(['id', 'name'], $result->columns());
        self::assertSame(2, $result->rowCount());

        self::assertNull((new Result())->first());
        self::assertSame([], (new Result())->all());
    }
}
