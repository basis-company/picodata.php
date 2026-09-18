<?php

declare(strict_types=1);

namespace Basis\Picodata\Test\Fixtures {

    use Basis\Picodata\Attribute\Column;
    use Basis\Picodata\Attribute\Distributed;
    use Basis\Picodata\Attribute\DistributedGlobally;
    use Basis\Picodata\Attribute\Engine;
    use Basis\Picodata\Attribute\PrimaryKey;
    use Basis\Picodata\Attribute\TableName;
    use Basis\Picodata\Attribute\Tier;
    use Basis\Picodata\Attribute\Unlogged;
    use Basis\Picodata\Indexing;
    use Basis\Picodata\Schema\Index;

    #[TableName('auth_user')]
    #[Engine('vinyl')]
    #[Distributed(columns: ['id'])]
    #[Tier('cold')]
    class AnnotatedUser implements Indexing
    {
        public function __construct(
            public int $id,
            #[Column(name: 'full_name')]
            public string $fullName,
            public string $email = '',
            public ?\DateTimeImmutable $created = null,
        ) {
        }

        public static function indexes(): array
        {
            return [
                new Index(name: 'user_name_idx', columns: ['full_name'], unique: true),
                new Index(columns: ['email'], using: 'HASH', unique: true),
            ];
        }
    }

    class PlainUser
    {
        public int $id = 0;
        public ?string $name = null;
    }

    class PartialUser
    {
        public int $id = 0;
        public string $note;
    }

    class NullablePk
    {
        public ?int $id = null;
        public string $label = '';
    }

    /** No attributes: table = snake(short), PK = first promoted param. */
    class TokenEntity
    {
        public function __construct(public string $token, public int $uses = 0)
        {
        }
    }

    #[PrimaryKey(columns: ['tenant_id', 'id'])]
    class Membership
    {
        public function __construct(public int $id, public int $tenant_id)
        {
        }
    }

    #[DistributedGlobally]
    #[Tier('hot')]
    #[Unlogged]
    class ConfigEntry
    {
        public function __construct(public string $key)
        {
        }
    }

    class User
    {
        public function __construct(public int $id)
        {
        }
    }

    #[TableName('custom')]
    class PrefixedThing
    {
        public function __construct(public int $id)
        {
        }
    }
}

namespace Basis\Picodata\Test {

    use Basis\Picodata\Exception\InvalidException;
    use Basis\Picodata\Exception\NotFoundException;
    use Basis\Picodata\Indexing;
    use Basis\Picodata\Map\ClassFactory;
    use Basis\Picodata\Map\Generator;
    use Basis\Picodata\Map\Mapper;
    use Basis\Picodata\Map\PrefixedResolver;
    use Basis\Picodata\Schema\Column as SColumn;
    use Basis\Picodata\Schema\Index as SIndex;
    use Basis\Picodata\Schema\Table as STable;
    use Basis\Picodata\Test\Fixtures\AnnotatedUser;
    use Basis\Picodata\Test\Fixtures\ConfigEntry;
    use Basis\Picodata\Test\Fixtures\Membership;
    use Basis\Picodata\Test\Fixtures\NullablePk;
    use Basis\Picodata\Test\Fixtures\PartialUser;
    use Basis\Picodata\Test\Fixtures\PlainUser;
    use Basis\Picodata\Test\Fixtures\PrefixedThing;
    use Basis\Picodata\Test\Fixtures\TokenEntity;
    use Basis\Picodata\Test\Fixtures\User;
    use PHPUnit\Framework\TestCase;

    final class MapperTest extends TestCase
    {
        private Mapper $mapper;

        protected function setUp(): void
        {
            Mapper::reset();
            ClassFactory::forget();
            $this->mapper = new Mapper();
        }

        public function testAnnotatedTableMetadata(): void
        {
            $t = $this->mapper->table(AnnotatedUser::class);

            self::assertSame('auth_user', $t->name);
            self::assertSame('vinyl', $t->engine);
            self::assertSame('cold', $t->tier);
            self::assertSame(['id'], $t->distributed);
            self::assertSame(['id'], $t->primary);

            $cols = $t->columns;
            self::assertSame(['id', 'full_name', 'email', 'created'], array_keys($cols));
            self::assertSame('INTEGER', $cols['id']->type);
            self::assertTrue($cols['id']->primary);
            self::assertFalse($cols['id']->nullable);
            self::assertSame('TEXT', $cols['full_name']->type);
            self::assertSame('DATETIME', $cols['created']->type);
            self::assertTrue($cols['created']->nullable);
        }

        public function testIndexesFromIndexingInterface(): void
        {
            $t = $this->mapper->table(AnnotatedUser::class);

            $byName = [];
            $unnamed = [];
            foreach ($t->indexes as $i) {
                if ($i->name === null) {
                    $unnamed[] = $i;
                } else {
                    $byName[$i->name] = $i;
                }
            }

            self::assertArrayHasKey('user_name_idx', $byName);
            self::assertSame(['full_name'], $byName['user_name_idx']->columns);
            self::assertTrue($byName['user_name_idx']->unique);

            self::assertCount(1, $unnamed, 'nameless index stays null for Ddl auto-name');
            self::assertSame(['email'], $unnamed[0]->columns);
            self::assertSame('HASH', $unnamed[0]->using);
            self::assertTrue($unnamed[0]->unique);
        }

        public function testDefaultsWithoutAttributes(): void
        {
            $t = $this->mapper->table(PlainUser::class);

            self::assertSame('plain_user', $t->name);
            self::assertSame('memtx', $t->engine);
            self::assertSame([], $t->distributed);
            self::assertSame([], $t->primary, 'no promoted params -> no primary key');

            $cols = $t->columns;
            self::assertSame('INTEGER', $cols['id']->type);
            self::assertFalse($cols['id']->primary);
            self::assertSame('TEXT', $cols['name']->type);
            self::assertTrue($cols['name']->nullable);
        }

        public function testFirstPromotedParamIsDefaultPrimaryKey(): void
        {
            $t = $this->mapper->table(TokenEntity::class);

            self::assertSame('token_entity', $t->name);
            self::assertSame(['token'], $t->primary);

            $cols = $t->columns;
            self::assertTrue($cols['token']->primary);
            self::assertFalse($cols['uses']->primary);
            self::assertSame('INTEGER', $cols['uses']->type);
        }

        public function testPrimaryKeyAttributeOverride(): void
        {
            $t = $this->mapper->table(Membership::class);

            self::assertSame(['tenant_id', 'id'], $t->primary);
            $cols = $t->columns;
            self::assertTrue($cols['tenant_id']->primary);
            self::assertTrue($cols['id']->primary);
        }

        public function testClassAttributesFeedTable(): void
        {
            $t = $this->mapper->table(ConfigEntry::class);

            self::assertSame('config_entry', $t->name);
            self::assertNull($t->distributed, 'DistributedGlobally -> null');
            self::assertSame('hot', $t->tier);
            self::assertTrue($t->unlogged);
            self::assertSame('memtx', $t->engine);
        }

        public function testNamingResolverDrivesInference(): void
        {
            $prefix = new Mapper(new PrefixedResolver(default: 'auth_'));
            self::assertSame('auth_user', $prefix->table(User::class)->name);
            self::assertSame('custom', $prefix->table(PrefixedThing::class)->name, '#[TableName] wins over the rule');

            $modular = new Mapper(new PrefixedResolver([
                'Basis\\Picodata\\Test\\Fixtures\\' => 'fixtures_',
            ]));
            self::assertSame('fixtures_user', $modular->table(User::class)->name);
            self::assertSame(
                User::class,
                $modular->resolveClass('fixtures_user'),
                'reverse rule maps the table back to its autoloadable class',
            );
        }

        public function testColumnsMapIsColumnToProperty(): void
        {
            self::assertSame(
                ['id' => 'id', 'full_name' => 'fullName', 'email' => 'email', 'created' => 'created'],
                $this->mapper->columns(AnnotatedUser::class),
            );
            self::assertSame(['id' => 'id', 'label' => 'label'], $this->mapper->columns(new NullablePk()));
        }

        public function testPrimaryKeyAndClassNameFor(): void
        {
            self::assertSame(['id'], $this->mapper->primaryKey(AnnotatedUser::class));
            self::assertSame(
                'Basis\\Picodata\\Runtime\\AuthUser',
                $this->mapper->classNameFor('auth_user'),
            );
            self::assertSame(
                'Basis\\Picodata\\Runtime\\PlainThing',
                $this->mapper->classNameFor('plain_thing'),
            );
        }

        public function testRowConvertsValues(): void
        {
            $u = new AnnotatedUser(7, 'Bob', 'b@x', new \DateTimeImmutable('2026-09-17 10:00:00'));

            self::assertSame(
                ['id' => 7, 'full_name' => 'Bob', 'email' => 'b@x', 'created' => '2026-09-17 10:00:00'],
                $this->mapper->row($u),
            );
            self::assertSame(
                ['id' => 7, 'full_name' => 'Bob'],
                $this->mapper->row($u, ['id', 'full_name']),
            );
        }

        public function testRowSkipsUninitializedProps(): void
        {
            self::assertSame(['id' => 0], $this->mapper->row(new PartialUser()));
        }

        public function testPkValues(): void
        {
            $u = new AnnotatedUser(1, 'a', 'a@x');
            self::assertSame(['id' => 1], $this->mapper->pkValues($u));

            self::assertNull($this->mapper->pkValues(new NullablePk()), 'no pk -> null');
        }

        public function testGeneratorRoundTripWithIndexes(): void
        {
            $t = new STable('round_tbl', columns: [
                new SColumn('id', 'INTEGER', primary: true),
                new SColumn('Weird Col', 'TEXT'),
                new SColumn('num', 'INTEGER', unsigned: true),
                new SColumn('tags', 'TEXT ARRAY'),
                new SColumn('name', 'TEXT', nullable: true),
            ], primary: ['id'], indexes: [
                new SIndex(name: 'round_name_idx', columns: ['name'], unique: true),
                new SIndex(columns: [['num', 'DESC']]),
            ]);

            $class = 'Basis\\Picodata\\Runtime\\G' . substr(md5('rt' . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 10);
            $src = Generator::source($t, $class);
            self::assertStringContainsString('use Basis\Picodata\Attribute\TableName;', $src);
            self::assertStringContainsString('implements \\Basis\\Picodata\\Indexing', $src);
            self::assertStringNotContainsString('as pico', $src);

            $fq = Generator::define($t);
            self::assertContains(Indexing::class, class_implements($fq));

            $this->assertSameMeta($t, $this->mapper->table($fq));
        }

        public function testGeneratorRoundTripTableAttributes(): void
        {
            $t = new STable(
                'wide_tbl',
                columns: [
                    new SColumn('a', 'TEXT'),
                    new SColumn('b', 'INTEGER', primary: true),
                ],
                primary: ['b'],
                engine: 'vinyl',
                distributed: ['b'],
                tier: 'cold',
                unlogged: true,
            );

            $fq = Generator::define($t, 'Basis\\Picodata\\Runtime\\W' . substr(md5('wt' . random_int(0, PHP_INT_MAX)), 0, 10));

            $this->assertSameMeta($t, $this->mapper->table($fq));
        }

        private function assertSameMeta(STable $expected, STable $actual): void
        {
            self::assertSame($expected->name, $actual->name);
            self::assertSame($expected->engine, $actual->engine);
            self::assertSame($expected->distributed, $actual->distributed);
            self::assertSame($expected->tier, $actual->tier);
            self::assertSame($expected->unlogged, $actual->unlogged);
            self::assertSame($expected->primary, $actual->primary);

            $actualCols = $actual->columns;
            foreach ($expected->columns as $name => $c) {
                self::assertArrayHasKey($name, $actualCols);
                $b = $actualCols[$name];
                self::assertSame(
                    [$c->type, $c->nullable, $c->unsigned, $c->array, $c->primary],
                    [$b->type, $b->nullable, $b->unsigned, $b->array, $b->primary],
                    "column {$name}",
                );
            }

            self::assertCount(count($expected->indexes), $actual->indexes);
            foreach ($expected->indexes as $n => $i) {
                $b = $actual->indexes[$n];
                self::assertSame([$i->name, $i->columns, $i->using, $i->unique], [$b->name, $b->columns, $b->using, $b->unique]);
            }
        }

        public function testTableNameTargetUsesRegistry(): void
        {
            $t = new STable('registry_probe', columns: [new SColumn('id', 'INTEGER', primary: true)]);
            ClassFactory::register('Registry_Probe', $t);

            self::assertSame($t, $this->mapper->table('registry_probe'));
            self::assertSame(['id' => 'id'], $this->mapper->columns('registry_probe'));
        }

        public function testUnknownTableNameThrows(): void
        {
            $this->expectException(InvalidException::class);
            $this->mapper->table('no_such_table');
        }

        public function testMissingClassThrowsNotFound(): void
        {
            $this->expectException(NotFoundException::class);
            $this->mapper->table('Basis\\Picodata\\Test\\Fixtures\\Nope');
        }

        public function testSnakeCase(): void
        {
            self::assertSame('auth_user', Mapper::snake('AuthUser'));
            self::assertSame('http_user', Mapper::snake('HTTPUser'));
            self::assertSame('id', Mapper::snake('id'));
            self::assertSame('full_name', Mapper::snake('fullName'));
        }
    }
}
