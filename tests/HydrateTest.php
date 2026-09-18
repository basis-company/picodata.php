<?php

declare(strict_types=1);

namespace Basis\Picodata\Test\HydrateFixtures {

    enum Status: string
    {
        case Active = 'active';
        case Banned = 'banned';
    }

    class CtorUser
    {
        public function __construct(
            public int $id,
            public string $name,
            public ?int $score = null,
            public bool $active = false,
            public float $ratio = 0.0,
            public ?\DateTimeImmutable $created = null,
            public Status $status = Status::Active,
            public array $tags = [],
        ) {
        }
    }

    class PlainBox
    {
        public int $id = 0;
        public string $label = '';
        public ?int $num = null;
    }
}

namespace Basis\Picodata\Test {

    use Basis\Picodata\Map\ClassFactory;
    use Basis\Picodata\Map\Generator;
    use Basis\Picodata\Map\Mapper;
    use Basis\Picodata\Schema\Column as SColumn;
    use Basis\Picodata\Schema\Table as STable;
    use Basis\Picodata\Test\HydrateFixtures\CtorUser;
    use Basis\Picodata\Test\HydrateFixtures\PlainBox;
    use Basis\Picodata\Test\HydrateFixtures\Status;
    use PHPUnit\Framework\TestCase;

    final class HydrateTest extends TestCase
    {
        private Mapper $mapper;

        protected function setUp(): void
        {
            Mapper::reset();
            ClassFactory::forget();
            $this->mapper = new Mapper();
        }

        public function testHydrateCastsDbStrings(): void
        {
            $u = $this->mapper->hydrate(CtorUser::class, [
                'id' => '5',
                'name' => 'bob',
                'score' => '42',
                'active' => 't',
                'ratio' => '1.25',
                'created' => '2026-09-17 10:00:00',
                'status' => 'banned',
                'tags' => '{"a","b,c"}',
            ]);

            self::assertSame(5, $u->id);
            self::assertSame('bob', $u->name);
            self::assertSame(42, $u->score);
            self::assertTrue($u->active);
            self::assertSame(1.25, $u->ratio);
            self::assertSame('2026-09-17 10:00:00', $u->created?->format('Y-m-d H:i:s'));
            self::assertSame(Status::Banned, $u->status);
            self::assertSame(['a', 'b,c'], $u->tags);
        }

        public function testHydrateJsonArrayAndBoolFalse(): void
        {
            $u = $this->mapper->hydrate(CtorUser::class, [
                'id' => 1,
                'name' => 'x',
                'active' => 'f',
                'tags' => '[1,"y"]',
            ]);

            self::assertFalse($u->active);
            self::assertSame([1, 'y'], $u->tags);
        }

        public function testHydrateMissingColumnsUseDefaults(): void
        {
            $u = $this->mapper->hydrate(CtorUser::class, ['id' => 1, 'name' => 'x']);

            self::assertNull($u->score);
            self::assertFalse($u->active);
            self::assertSame(0.0, $u->ratio);
            self::assertNull($u->created);
            self::assertSame(Status::Active, $u->status);
            self::assertSame([], $u->tags);
        }

        public function testHydrateNullsAndTimestampInt(): void
        {
            $u = $this->mapper->hydrate(CtorUser::class, [
                'id' => 1,
                'name' => 'x',
                'score' => null,
                'created' => 1758100000,
            ]);

            self::assertNull($u->score);
            self::assertSame(1758100000, $u->created?->getTimestamp());
        }

        public function testHydrateNonCtorClassFallback(): void
        {
            $b = $this->mapper->hydrate(PlainBox::class, ['id' => '9', 'label' => 'hi', 'num' => null]);

            self::assertSame(9, $b->id);
            self::assertSame('hi', $b->label);
            self::assertNull($b->num);
        }

        public function testFillRefreshesExistingInstance(): void
        {
            $u = new CtorUser(1, 'old');

            $this->mapper->fill($u, ['name' => 'new', 'score' => '42', 'tags' => '{"x"}']);

            self::assertSame('new', $u->name);
            self::assertSame(42, $u->score);
            self::assertSame(['x'], $u->tags);
            self::assertSame(1, $u->id, 'column absent from row stays untouched');
        }

        public function testRoundTripThroughRow(): void
        {
            $u = $this->mapper->hydrate(CtorUser::class, ['id' => 3, 'name' => 'n', 'status' => 'banned', 'tags' => ['p', 'q']]);
            $row = $this->mapper->row($u);

            self::assertSame('banned', $row['status']);
            self::assertSame('["p","q"]', $row['tags']);
            self::assertSame(['id' => 3], $this->mapper->pkValues($u));
        }

        public function testGeneratorSourceCompiles(): void
        {
            $class = 'Basis\\Picodata\\Runtime\\G' . substr(md5('src' . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 10);
            $t = new STable('gen_probe', columns: [
                new SColumn('id', 'INTEGER', primary: true),
                new SColumn('name', 'TEXT', nullable: true),
                new SColumn('meta', 'JSON', nullable: true),
                new SColumn('created_at', 'DATETIME', nullable: true),
                new SColumn('tags', 'TEXT ARRAY'),
            ], primary: ['id']);

            $src = Generator::source($t, $class);
            self::assertStringContainsString('<?php', $src);
            self::assertStringContainsString('declare(strict_types=1);', $src);

            eval(str_replace('<?php', '', $src));
            self::assertTrue(class_exists($class));

            $back = $this->mapper->table($class);
            self::assertSame('gen_probe', $back->name);
            self::assertSame(['id'], $back->primary);
            self::assertSame('DATETIME', $back->columns['created_at']->type);
            self::assertSame('JSON', $back->columns['meta']->type);
        }

        public function testGeneratorDefineIsStable(): void
        {
            $t = new STable('define_probe', columns: [new SColumn('id', 'INTEGER', primary: true)], primary: ['id']);

            $fq1 = Generator::define($t);
            $fq2 = Generator::define($t);

            self::assertSame($fq1, $fq2, 'stable generated class name');
            self::assertStringStartsWith('Basis\\Picodata\\Runtime\\T_', $fq1);

            self::assertTrue(class_exists($fq1));
        }

        public function testResolveClassMaterializesRegisteredTable(): void
        {
            $t = new STable('resolved_tbl', columns: [
                new SColumn('id', 'INTEGER', primary: true),
                new SColumn('label', 'TEXT', nullable: true),
            ], primary: ['id']);
            ClassFactory::register('resolved_tbl', $t);

            $class = $this->mapper->resolveClass('resolved_tbl');
            self::assertSame('Basis\\Picodata\\Runtime\\ResolvedTbl', $class);
            self::assertTrue(class_exists($class));

            $obj = $this->mapper->hydrate($class, ['id' => '8', 'label' => 'ok']);
            self::assertSame(8, $obj->id);
            self::assertSame('ok', $obj->label);
            self::assertSame(['id' => 8], $this->mapper->pkValues($obj));
        }

        public function testClassFactoryMake(): void
        {
            $t = new STable('widget_zz', columns: [
                new SColumn('id', 'INTEGER', primary: true),
                new SColumn('title', 'TEXT', nullable: true),
            ], primary: ['id']);
            ClassFactory::register('widget_zz', $t);

            $fqcn = (new ClassFactory())->make('widget_zz');
            self::assertSame('Basis\\Picodata\\Runtime\\WidgetZz', $fqcn);
            self::assertTrue(class_exists($fqcn));
            self::assertSame($t, $this->mapper->table('widget_zz'));

            // second call reuses the defined class
            self::assertSame($fqcn, (new ClassFactory())->make('widget_zz'));

            $obj = $this->mapper->hydrate($fqcn, ['id' => '4', 'title' => 'w']);
            self::assertSame(4, $obj->id);
            self::assertSame('w', $obj->title);
        }

        public function testClassFactoryUnknownTableThrows(): void
        {
            $this->expectException(\Basis\Picodata\Exception\InvalidException::class);
            (new ClassFactory())->make('never_registered');
        }
    }
}
