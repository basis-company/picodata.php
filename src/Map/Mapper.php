<?php

declare(strict_types=1);

namespace Basis\Picodata\Map;

use Basis\Picodata\Attribute\Column as ColumnAttr;
use Basis\Picodata\Attribute\Distributed as DistributedAttr;
use Basis\Picodata\Attribute\DistributedGlobally as DistributedGloballyAttr;
use Basis\Picodata\Attribute\Engine as EngineAttr;
use Basis\Picodata\Attribute\PrimaryKey as PrimaryKeyAttr;
use Basis\Picodata\Attribute\TableName as TableNameAttr;
use Basis\Picodata\Attribute\Tier as TierAttr;
use Basis\Picodata\Attribute\Unlogged as UnloggedAttr;
use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Exception\NotFoundException;
use Basis\Picodata\Indexing;
use Basis\Picodata\Schema\Column;
use Basis\Picodata\Schema\Index;
use Basis\Picodata\Schema\Table;

/**
 * Reflection-driven entity <-> Table metadata and row hydrator.
 *
 * Every promoted constructor property becomes a column automatically:
 * SQL type and nullability derive from the PHP type, the table name from
 * the snake_case short class name, the primary key from the first promoted
 * property. Small class attributes (TableName, Engine, Tier, Distributed,
 * DistributedGlobally, Unlogged, PrimaryKey) override what types cannot
 * express; secondary indexes come from the Indexing interface.
 * Plain table-name targets resolve through the ClassFactory registry.
 */
final class Mapper
{
    /** @var array<string, array> resolver-independent reflection metadata, keyed by class */
    private static array $reflect = [];

    /** @var array<string, Table> built Tables for this mapper's resolver, keyed by class */
    private array $tables = [];

    private readonly Resolver $resolver;

    private readonly ClassFactory $factory;

    public function __construct(?Resolver $resolver = null, ?ClassFactory $factory = null)
    {
        $this->resolver = $resolver ?? new PrefixedResolver();
        $this->factory = $factory ?? new ClassFactory();
    }

    /** The naming rule in effect. */
    public function resolver(): Resolver
    {
        return $this->resolver;
    }

    /** Clear the resolver-independent reflection metadata cache (tests). */
    public static function reset(): void
    {
        self::$reflect = [];
    }

    /** Metadata Table for a class, object instance, or registered table name. */
    public function table(string|object $target): Table
    {
        if ($target instanceof Table) {
            return $target;
        }
        if (is_object($target)) {
            return $this->meta($target::class)['table'];
        }
        if (str_contains($target, '\\') || class_exists($target)) {
            if (!class_exists($target)) {
                throw new NotFoundException("class {$target} not found");
            }
            return $this->meta($target)['table'];
        }
        return ClassFactory::tableFor($target)
            ?? throw new InvalidException("unknown table metadata for {$target}");
    }

    /**
     * Column map for a target: [column-name => property-name].
     *
     * @return array<string, string>
     */
    public function columns(string|object $target): array
    {
        if (is_object($target) || str_contains($target, '\\') || class_exists($target)) {
            return $this->meta(is_object($target) ? $target::class : $target)['cols'];
        }
        $t = ClassFactory::tableFor($target)
            ?? throw new InvalidException("unknown table metadata for {$target}");
        return array_combine(
            array_keys($t->columns),
            array_map(strtolower(...), array_keys($t->columns)),
        );
    }

    /**
     * Primary key column names, ordered.
     *
     * @return string[]
     */
    public function primaryKey(string|object $target): array
    {
        return array_values($this->table($target)->primary);
    }

    /** Runtime class name that ClassFactory would use for a table. */
    public function classNameFor(string $tableName): string
    {
        return ClassFactory::className($tableName);
    }

    /**
     * Resolve the entity class for a registered table name: the naming rule's
     * reverse mapping when it points at an autoloadable class, else a runtime
     * class (reused when already defined, otherwise generated once).
     */
    public function resolveClass(string $tableName): string
    {
        $mapped = $this->resolver->classOf($tableName);
        if ($mapped !== null && class_exists($mapped, true)) {
            return $mapped;
        }

        $runtime = ClassFactory::className($tableName);
        if (class_exists($runtime, true)) {
            return $runtime;
        }

        return $this->factory->make($tableName, $this->resolver);
    }

    /**
     * Build an entity instance from a column-keyed DB row: construct with
     * cast promoted values (when any), then fill() every column present.
     */
    public function hydrate(string $class, array $row): object
    {
        $meta = $this->meta($class);
        $rc = new \ReflectionClass($class);

        if ($meta['promoted'] !== []) {
            $args = [];
            foreach ($meta['cols'] as $col => $prop) {
                if (!isset($meta['promoted'][$prop]) || !array_key_exists($col, $row)) {
                    continue; // absent column -> constructor default (or ArgumentCountError)
                }
                $args[$prop] = Types::cast($row[$col], self::paramType($meta['promoted'][$prop]));
            }
            $obj = $rc->newInstanceArgs($args);
        } else {
            $obj = $rc->newInstanceWithoutConstructor();
        }
        $this->fill($obj, $row);
        return $obj;
    }

    /**
     * Refresh an existing entity in place: assign (with casting) every
     * column present in $row. Static and readonly properties cannot be
     * re-set and are left untouched.
     */
    public function fill(object $entity, array $row): void
    {
        $meta = $this->meta($entity::class);
        foreach ($meta['cols'] as $col => $prop) {
            if (!array_key_exists($col, $row) || !isset($meta['props'][$prop])) {
                continue;
            }
            $rp = $meta['props'][$prop];
            if ($rp->isStatic() || $rp->isReadOnly()) {
                continue;
            }
            $rp->setValue($entity, Types::cast($row[$col], self::propType($rp)));
        }
    }

    /**
     * Bindable column => param map for an entity; uninitialized props skipped.
     *
     * @param  string[]|null  $only  restrict to these column names
     * @return array<string, mixed>
     */
    public function row(object $entity, ?array $only = null): array
    {
        $meta = $this->meta($entity::class);
        $out = [];
        foreach ($meta['cols'] as $col => $prop) {
            if ($only !== null && !in_array($col, $only, true) && !in_array($prop, $only, true)) {
                continue;
            }
            $rp = $meta['props'][$prop];
            if (!$rp->isInitialized($entity)) {
                continue;
            }
            $out[$col] = Types::toParam($rp->getValue($entity));
        }
        return $out;
    }

    /** [pk-column => param] when every PK prop is initialized and non-null, else null. */
    public function pkValues(object $entity): ?array
    {
        $meta = $this->meta($entity::class);
        $out = [];
        foreach ($this->table($entity)->primary as $col) {
            if (!isset($meta['props'][$meta['cols'][$col] ?? ''])) {
                return null;
            }
            $rp = $meta['props'][$meta['cols'][$col]];
            if (!$rp->isInitialized($entity) || $rp->getValue($entity) === null) {
                return null;
            }
            $out[$col] = Types::toParam($rp->getValue($entity));
        }
        return $out === [] ? null : $out;
    }

    /** camelCase / CamelCase -> snake_case. */
    public static function snake(string $name): string
    {
        $s = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $name) ?? $name;
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $s) ?? $s;
        return strtolower($s);
    }

    /** snake_case -> CamelCase (best-effort inverse of snake, for reverse name mapping). */
    public static function camel(string $name): string
    {
        $parts = preg_split('/_+/', strtolower(trim($name, '_'))) ?: [];

        return implode('', array_map(ucfirst(...), array_filter($parts, strlen(...))));
    }

    /**
     * Per-instance metadata view: the resolver-independent reflection bundle
     * (shared across mappers) plus the Table built with this mapper's resolver.
     *
     * @return array{table: Table, cols: array<string,string>, props: array<string,\ReflectionProperty>, promoted: array<string,\ReflectionParameter>}
     */
    private function meta(string $class): array
    {
        $r = self::reflect($class);
        $table = $this->tables[$class] ??= new Table(
            name: $r['nameAttr'] ?? $this->resolver->tableOf($class),
            columns: $r['columns'],
            primary: $r['primary'],
            engine: $r['engine'],
            distributed: $r['distributed'],
            tier: $r['tier'],
            unlogged: $r['unlogged'],
            indexes: $r['indexes'],
        );

        return ['table' => $table, 'cols' => $r['cols'], 'props' => $r['props'], 'promoted' => $r['promoted']];
    }

    /**
     * Cached, resolver-independent reflection of a class: columns, key, engine,
     * distribution and indexes. Only the table name depends on the naming rule,
     * so it is left to {@see meta()} to inject (from `#[TableName]` or resolver).
     */
    private static function reflect(string $class): array
    {
        if (isset(self::$reflect[$class])) {
            return self::$reflect[$class];
        }

        $rc = new \ReflectionClass($class);
        $classAttr = static fn (string $a): ?object => ($rc->getAttributes($a)[0] ?? null)?->newInstance();

        $tableName = $classAttr(TableNameAttr::class)?->name;
        $engine = $classAttr(EngineAttr::class)?->engine ?? 'memtx';
        $tier = $classAttr(TierAttr::class)?->name;
        $global = $classAttr(DistributedGloballyAttr::class) !== null;
        $unlogged = $classAttr(UnloggedAttr::class) !== null;
        $distributedAttr = $classAttr(DistributedAttr::class);
        $primaryAttr = $classAttr(PrimaryKeyAttr::class);

        $cols = [];     // column => property
        $props = [];
        $promoted = [];
        $defs = [];     // column => [sqlType, nullable, unsigned, array]

        foreach ($rc->getConstructor()?->getParameters() ?? [] as $p) {
            if ($p->isPromoted()) {
                $promoted[$p->getName()] = $p;
            }
        }

        // All promoted constructor parameters (declaration order) become
        // columns first, then the remaining non-promoted public properties.
        $slots = [];
        foreach (array_keys($promoted) as $propName) {
            $slots[] = $rc->getProperty($propName);
        }
        foreach ($rc->getProperties() as $rp) {
            if ($rp->isStatic() || !$rp->isPublic() || isset($promoted[$rp->getName()])) {
                continue;
            }
            $slots[] = $rp;
        }

        foreach ($slots as $rp) {
            $attr = ($rp->getAttributes(ColumnAttr::class)[0] ?? null)?->newInstance();
            $name = $attr?->name ?? self::snake($rp->getName());

            $nullable = false;
            $phpType = 'mixed';
            $type = $rp->getType();
            if ($type instanceof \ReflectionNamedType) {
                $nullable = $type->allowsNull() && $type->getName() !== 'mixed';
                $phpType = $type->getName();
            } elseif ($type !== null) {
                $nullable = $type->allowsNull();
                foreach ($type->getTypes() as $part) {
                    if ($part instanceof \ReflectionNamedType && $part->getName() !== 'null') {
                        $phpType = $part->getName();
                        break;
                    }
                }
            }

            $sqlType = $attr?->type;
            if ($sqlType === null) {
                try {
                    $sqlType = Types::sqlOf($phpType);
                } catch (InvalidException) {
                    $sqlType = Column::TEXT; // mixed / untyped
                }
            }

            $cols[$name] = $rp->getName();
            $props[$rp->getName()] = $rp;
            $defs[$name] = [$sqlType, $nullable, $attr?->unsigned ?? false, $attr?->array ?? false];
        }

        if ($primaryAttr !== null) {
            $primaryList = array_values(array_unique(array_map(
                static fn (string $c): string => self::resolveCol($c, $cols, $class),
                $primaryAttr->columns,
            )));
        } else {
            $first = array_key_first($promoted);
            $primaryList = $first === null ? [] : [(string) array_search($first, $cols, true)];
        }

        $columns = [];
        foreach ($defs as $name => [$sqlType, $nullable, $unsigned, $isArray]) {
            $columns[$name] = new Column(
                name: $name,
                type: $sqlType,
                nullable: $nullable,
                array: $isArray,
                unsigned: $unsigned,
                primary: in_array($name, $primaryList, true),
            );
        }

        $indexes = [];
        if ($rc->implementsInterface(Indexing::class)) {
            foreach ($class::indexes() as $ix) {
                $indexes[] = new Index(
                    name: $ix->name, // null = auto-name by Ddl
                    columns: array_map(
                        static fn ($c) => is_string($c) ? self::resolveCol($c, $cols, $class) : $c,
                        $ix->columns,
                    ),
                    using: $ix->using,
                    unique: $ix->unique,
                );
            }
        }

        return self::$reflect[$class] = [
            'nameAttr' => $tableName,
            'columns' => array_values($columns),
            'primary' => $primaryList,
            'engine' => $engine,
            'distributed' => match (true) {
                $global => null,
                $distributedAttr !== null => array_values($distributedAttr->columns),
                default => [],
            },
            'tier' => $tier,
            'unlogged' => $unlogged,
            'indexes' => $indexes,
            'cols' => $cols,
            'props' => $props,
            'promoted' => $promoted,
        ];
    }

    /** Resolve an index column reference (column or property name) to a column. */
    private static function resolveCol(string $ref, array $cols, string $class): string
    {
        if (in_array($ref, array_keys($cols), true)) {
            return $ref;
        }
        if (in_array($ref, array_values($cols), true)) {
            return array_search($ref, $cols, true);
        }
        $snake = self::snake($ref);
        if (isset($cols[$snake])) {
            return $snake;
        }
        throw new InvalidException("index column {$ref} not found on {$class}");
    }

    /** PHP type name of a promoted parameter for Types::cast. */
    private static function paramType(\ReflectionParameter $p): string
    {
        $t = $p->getType();
        return $t instanceof \ReflectionNamedType ? $t->getName() : 'mixed';
    }

    /** PHP type name of a property for Types::cast. */
    private static function propType(\ReflectionProperty $p): string
    {
        $t = $p->getType();
        if ($t instanceof \ReflectionNamedType) {
            return $t->getName();
        }
        if ($t !== null) {
            foreach ($t->getTypes() as $part) {
                if ($part instanceof \ReflectionNamedType && $part->getName() !== 'null') {
                    return $part->getName();
                }
            }
        }
        return 'mixed';
    }
}
