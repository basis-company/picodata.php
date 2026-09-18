<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Map\ClassFactory;
use Basis\Picodata\Map\Generator;
use Basis\Picodata\Map\Mapper;
use Basis\Picodata\Schema\Column;
use Basis\Picodata\Schema\Table;
use PHPUnit\Framework\TestCase;

final class MaterializationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/picodata-materialize-' . bin2hex(random_bytes(4));
        ClassFactory::$materializePath = null;
        ClassFactory::forget();
    }

    protected function tearDown(): void
    {
        ClassFactory::$materializePath = null;
        ClassFactory::forget();
        array_map(unlink(...), glob($this->dir . '/*.php') ?: []);
        @rmdir($this->dir);
    }

    private function register(string $table): void
    {
        ClassFactory::register($table, new Table(
            $table,
            [new Column('id', 'INTEGER'), new Column('name', 'TEXT', nullable: true)],
            primary: ['id'],
        ));
    }

    public function test_generated_class_is_written_and_required(): void
    {
        $this->register('mat_writer');
        ClassFactory::$materializePath = $this->dir;

        $class = (new ClassFactory())->make('mat_writer');

        self::assertSame(ClassFactory::RUNTIME . '\\MatWriter', $class);
        self::assertFileExists($this->dir . '/MatWriter.php');
        self::assertTrue(class_exists($class, false));

        $user = (new Mapper())->hydrate($class, ['id' => '7', 'name' => 'x']);
        self::assertSame(7, $user->id);
        self::assertSame('x', $user->name);
    }

    public function test_existing_file_is_required_not_regenerated(): void
    {
        $this->register('mat_kept');
        mkdir($this->dir, 0o777, true);
        ClassFactory::$materializePath = $this->dir;
        file_put_contents(
            $this->dir . '/MatKept.php',
            Generator::source(ClassFactory::tableFor('mat_kept'), ClassFactory::RUNTIME . '\\MatKept'),
        );

        $class = (new ClassFactory())->make('mat_kept');

        self::assertSame(ClassFactory::RUNTIME . '\\MatKept', $class);
        self::assertTrue(class_exists($class, false));
        self::assertSame(
            Generator::source(ClassFactory::tableFor('mat_kept'), ClassFactory::RUNTIME . '\\MatKept'),
            file_get_contents($this->dir . '/MatKept.php'),
        );
    }

    public function test_eval_is_used_without_materialize_path(): void
    {
        $this->register('mat_eval');
        ClassFactory::$materializePath = null;

        $class = (new ClassFactory())->make('mat_eval');

        self::assertTrue(class_exists($class, false));
        self::assertDirectoryDoesNotExist($this->dir);
    }
}
