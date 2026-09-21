<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Quoter;
use PHPUnit\Framework\TestCase;

/**
 * sbroad's parser reserves plain lowercase words in column position;
 * Quoter::identifier must quote every one of them. The list is pinned by
 * live-parser probes — 'filter' and 'option' were missed in 0.0.2 and broke
 * CREATE TABLE for entities carrying those columns.
 */
final class KeywordQuotingTest extends TestCase
{
    public function testReservedWordsAreQuoted(): void
    {
        foreach (['window', 'filter', 'option', 'group', 'key', 'table', 'current'] as $word) {
            self::assertSame('"' . $word . '"', Quoter::identifier($word), $word);
        }
    }

    public function testPlainNamesStayBare(): void
    {
        self::assertSame('name', Quoter::identifier('name'));
        self::assertSame('public.table_name', Quoter::identifier('public.table_name'));
        self::assertSame('public."group"', Quoter::identifier('public.group'));
    }
}
