<?php

declare(strict_types=1);

namespace Basis\Picodata\Map;

use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Schema\Table;

/**
 * Registry of known table metadata and on-demand generation of entity classes
 * for tables that have no handwritten mapping.
 */
final class ClassFactory
{
    /** Runtime namespace for generated classes. */
    public const RUNTIME = 'Basis\\Picodata\\Runtime';

    /** @var array<string, Table> lowercased table name => metadata */
    public static array $tables = [];

    /**
     * When set, generated classes are written here (one file per class,
     * namespaced subdirs) and required, so they persist across processes.
     * Null (default) evals each class once per process.
     */
    public static ?string $materializePath = null;

    /** Register introspected metadata for a table (lowercased key). */
    public static function register(string $tableName, Table $t): void
    {
        self::$tables[strtolower($tableName)] = $t;
    }

    /** Registered metadata for a table, or null. */
    public static function tableFor(string $tableName): ?Table
    {
        return self::$tables[strtolower($tableName)] ?? null;
    }

    /** Clear the registry (tests). */
    public static function forget(): void
    {
        self::$tables = [];
    }

    /**
     * PSR-4-ish class name for a table: dots become namespace separators,
     * each part CamelCased. 'auth.user' -> Basis\Picodata\Runtime\Auth\User.
     */
    public static function className(string $tableName): string
    {
        $out = [];
        foreach (preg_split('/[.\/]/', strtolower(trim($tableName))) ?: [] as $part) {
            $part = preg_replace('/[^a-z0-9_]/', '_', $part) ?? '';
            $words = array_filter(explode('_', preg_replace('/_+/', '_', trim($part, '_')) ?? ''), strlen(...));
            $word = implode('', array_map(ucfirst(...), $words));
            if ($word !== '') {
                $out[] = ctype_digit($word[0]) ? '_' . $word : $word;
            }
        }
        return self::RUNTIME . '\\' . ($out === [] ? 'T' : implode('\\', $out));
    }

    /**
     * FQCN of a usable entity class for a registered table: reuse an
     * autoloadable class when present, else materialize it — to
     * self::$materializePath (file + require) when set, else eval.
     */
    public function make(string $tableName, ?Resolver $resolver = null): string
    {
        $class = self::className($tableName);
        if (class_exists($class, true)) {
            return $class;
        }

        $t = self::tableFor($tableName)
            ?? throw new InvalidException("unknown table metadata for {$tableName}");

        if (self::$materializePath === null) {
            return Generator::define($t, $class, $resolver);
        }

        $relative = str_replace('\\', '/', substr($class, strlen(self::RUNTIME) + 1));
        $file = rtrim(self::$materializePath, '/') . '/' . $relative . '.php';

        if (!is_file($file)) {
            $dir = dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
                throw new InvalidException("cannot create class directory {$dir}");
            }
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (file_put_contents($tmp, Generator::source($t, $class, $resolver)) === false || !rename($tmp, $file)) {
                throw new InvalidException("cannot write class file {$file}");
            }
        }

        require_once $file;

        return $class;
    }
}
