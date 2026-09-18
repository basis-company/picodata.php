<?php

declare(strict_types=1);

namespace Basis\Picodata\Test\ResolverFixtures {

    final class Widget
    {
        public function __construct(public int $id)
        {
        }
    }
}

namespace Basis\Picodata\Test {

    use Basis\Picodata\Map\Mapper;
    use Basis\Picodata\Map\PrefixedResolver;
    use Basis\Picodata\Map\Resolver;
    use Basis\Picodata\Test\ResolverFixtures\Widget;
    use PHPUnit\Framework\TestCase;

    final class ResolverTest extends TestCase
    {
        public function testNamespaceMapForwardMapping(): void
        {
            $r = new PrefixedResolver([
                'App\\Auth\\' => 'auth_',
                'App\\Billing\\' => 'bill_',
            ], default: 'gen_');

            self::assertSame('auth_user', $r->tableOf('App\\Auth\\User'));
            self::assertSame('bill_invoice', $r->tableOf('App\\Billing\\Invoice'));
            self::assertSame('gen_report', $r->tableOf('App\\Other\\Report'));
            self::assertSame('auth_', $r->prefixOf('App\\Auth\\User'));
        }

        public function testLongestNamespacePrefixWins(): void
        {
            $r = new PrefixedResolver([
                'App\\' => 'app_',
                'App\\Auth\\' => 'auth_',
            ]);
            self::assertSame('app_setting', $r->tableOf('App\\Config\\Setting'));
            self::assertSame('auth_user', $r->tableOf('App\\Auth\\User'));
        }

        public function testReverseMapsOnlyToLoadableClasses(): void
        {
            $r = new PrefixedResolver([
                'Basis\\Picodata\\Test\\ResolverFixtures\\' => 'w_',
                'App\\Missing\\' => 'm_',
            ]);

            self::assertSame(Widget::class, $r->classOf('w_widget'));
            self::assertNull($r->classOf('m_gone'), 'a computed class that cannot be loaded yields null');
            self::assertNull($r->classOf('no_rule_here'), 'no rule matches the table prefix');
        }

        public function testCustomResolverDrivesMapper(): void
        {
            $mapper = new Mapper(new class () implements Resolver {
                public function tableOf(string $class): string
                {
                    return match ($class) {
                        Widget::class => 'the_widgets',
                        default => 'unknown',
                    };
                }

                public function classOf(string $table): ?string
                {
                    return $table === 'the_widgets' ? Widget::class : null;
                }
            });

            self::assertSame('the_widgets', $mapper->table(Widget::class)->name);
            self::assertSame(Widget::class, $mapper->resolveClass('the_widgets'));
        }
    }
}
