<?php

declare(strict_types=1);

namespace Basis\Picodata\Map;

use Basis\Picodata\Schema\Table;

/**
 * Emits PHP entity-class source for an introspected Schema\Table.
 *
 * Style: direct imports of only the attribute classes actually used,
 * attributes only where they deviate from the reflection defaults
 * (name, memtx engine, PK = first column, PK distribution, TREE indexes).
 * Tables with secondary indexes gain an Indexing implementation.
 */
final class Generator
{
    /**
     * Full PHP file text (<?php, strict_types, namespace, imports, attributes)
     * for a final class $class mapping table $t via promoted constructor props.
     * A Resolver, when given, decides whether the class needs an explicit
     * #[TableName] (omitted when the naming rule already reproduces $t->name).
     */
    public static function source(Table $t, string $class, ?Resolver $resolver = null): string
    {
        [$ns, $name] = self::splitClass($class);
        $head = "<?php\n\ndeclare(strict_types=1);\n\n";
        if ($ns !== '') {
            $head .= 'namespace ' . $ns . ";\n\n";
        }
        return $head . self::classText($t, $name, $class, $resolver);
    }

    /**
     * Runtime variant: eval a class body (no <?php/declare) into
     * Basis\Picodata\Runtime (or $fqcn when given), guarded by class_exists.
     * Returns the FQCN.
     */
    public static function define(Table $t, ?string $fqcn = null, ?Resolver $resolver = null): string
    {
        if ($fqcn === null) {
            $ns = ClassFactory::RUNTIME;
            $name = 'T_' . substr(md5(strtolower($t->name)), 0, 16);
        } else {
            [$ns, $name] = self::splitClass($fqcn);
        }
        $fq = $ns === '' ? $name : $ns . '\\' . $name;
        if (class_exists($fq, false)) {
            return $fq;
        }
        eval(($ns === '' ? '' : 'namespace ' . $ns . ";\n") . self::classText($t, $name, $fq, $resolver));
        return $fq;
    }

    /** Class declaration text (imports + attributes + final class) with a safe name. */
    private static function classText(Table $t, string $name, string $fqcn, ?Resolver $resolver = null): string
    {
        /** @var array<string, true> $used attribute short names actually emitted */
        $used = [];
        $attr = static function (string $cls, string $args = '') use (&$used): string {
            $used[$cls] = true;
            return '#[' . $cls . ($args === '' ? '' : '(' . $args . ')') . ']';
        };

        $attrs = [];

        // Emit #[TableName] only when reflection of this class (via the naming
        // rule) would not already reproduce the table's physical name.
        $inferred = $resolver !== null ? $resolver->tableOf($fqcn) : Mapper::snake($name);
        if ($t->name !== $inferred) {
            $attrs[] = $attr('TableName', self::export($t->name));
        }
        if ($t->engine !== 'memtx') {
            $attrs[] = $attr('Engine', self::export($t->engine));
        }
        if ($t->distributed === null) {
            $attrs[] = $attr('DistributedGlobally');
        } elseif ($t->distributed !== []) {
            $attrs[] = $attr('Distributed', 'columns: [' . implode(', ', array_map(self::export(...), $t->distributed)) . ']');
        }
        if ($t->tier !== null) {
            $attrs[] = $attr('Tier', self::export($t->tier));
        }
        if ($t->unlogged) {
            $attrs[] = $attr('Unlogged');
        }

        $req = $opt = [];
        $reqCols = $optCols = [];
        foreach ($t->columns as $colName => $c) {
            $prop = preg_match('/^[a-z_][a-z0-9_]*$/i', $colName) === 1
                ? $colName
                : self::degenerate($colName);

            $php = Types::phpOf($c->type);
            $isArray = $c->array || $php === 'array';
            $typeText = $isArray || $php === null
                ? 'mixed'
                : (in_array($php, ['int', 'float', 'bool', 'string'], true) ? $php : '\\' . $php);

            $cargs = [];
            if ($colName !== $prop) {
                $cargs[] = 'name: ' . self::export($colName);
            }
            if (self::roundTrip($php, $isArray) !== strtoupper($c->type)) {
                $cargs[] = 'type: ' . self::export(strtoupper($c->type));
            }
            if ($isArray && $php !== 'array') {
                $cargs[] = 'array: true';
            }
            if ($c->unsigned) {
                $cargs[] = 'unsigned: true';
            }

            $decl = $typeText === 'mixed' ? 'mixed' : ($c->nullable ? '?' . $typeText : $typeText);
            $default = $c->nullable
                ? ' = null'
                : ((is_int($c->default) || is_float($c->default) || is_bool($c->default) || is_string($c->default))
                    ? ' = ' . self::export($c->default)
                    : '');
            $line = '        '
                . ($cargs === [] ? '' : $attr('Column', implode(', ', $cargs)) . "\n        ")
                . 'public ' . $decl . ' $' . $prop . $default;
            if ($c->nullable) {
                $opt[] = $line;
                $optCols[] = $colName;
            } else {
                $req[] = $line;
                $reqCols[] = $colName;
            }
        }

        // Required params first (PHP 8.5 deprecates optional-before-required);
        // the default primary key of the generated class is its first param.
        $params = [...$req, ...$opt];
        $paramCols = [...$reqCols, ...$optCols];
        $defaultPrimary = $paramCols === [] ? [] : [$paramCols[0]];
        if ($t->primary !== $defaultPrimary) {
            $attrs[] = $attr('PrimaryKey', 'columns: [' . implode(', ', array_map(self::export(...), $t->primary)) . ']');
        }

        $nl = count($params) > 1;
        $ctor = "    public function __construct(\n"
            . implode(",\n", $params)
            . ($nl ? ",\n" : "\n")
            . "    ) {\n    }\n";
        if ($params === []) {
            $ctor = "    public function __construct() {\n    }\n";
        }

        $body = $ctor;
        $implements = '';
        if ($t->indexes !== []) {
            $implements = ' implements \\Basis\\Picodata\\Indexing';
            $items = [];
            foreach ($t->indexes as $ix) {
                $ia = [];
                if ($ix->name !== null) {
                    $ia[] = 'name: ' . self::export($ix->name);
                }
                $ia[] = 'columns: [' . implode(', ', array_map(self::export(...), $ix->columns)) . ']';
                if ($ix->using !== 'TREE') {
                    $ia[] = 'using: ' . self::export($ix->using);
                }
                if ($ix->unique) {
                    $ia[] = 'unique: true';
                }
                $items[] = 'new \\Basis\\Picodata\\Schema\\Index(' . implode(', ', $ia) . ')';
            }
            $body .= "\n    public static function indexes(): array\n    {\n        return [\n            "
                . implode(",\n            ", $items) . ",\n        ];\n    }\n";
        }

        $useText = '';
        foreach (array_keys($used) as $cls) {
            $useText .= 'use Basis\\Picodata\\Attribute\\' . $cls . ";\n";
        }
        if ($useText !== '') {
            $useText .= "\n";
        }

        return $useText
            . ($attrs === [] ? '' : implode("\n", $attrs) . "\n")
            . 'final class ' . $name . $implements . "\n{\n" . $body . "}\n";
    }

    /** SQL type a fresh reflection of the emitted property would derive. */
    private static function roundTrip(?string $php, bool $isArray): string
    {
        try {
            return $isArray ? Types::sqlOf('mixed') : Types::sqlOf($php ?? 'mixed');
        } catch (\Throwable) {
            return '';
        }
    }

    /** Lowercase identifier-safe rewrite of an awkward column name. */
    private static function degenerate(string $name): string
    {
        $s = preg_replace('/[^a-z0-9_]/', '_', strtolower($name)) ?? '_';
        $s = preg_replace('/_+/', '_', trim($s, '_')) ?? '';
        if ($s === '' || ctype_digit($s[0])) {
            $s = '_' . $s;
        }
        return $s;
    }

    /** @return array{0:string,1:string} [namespace, sanitized short name] */
    private static function splitClass(string $class): array
    {
        $pos = strrpos($class, '\\');
        $ns = $pos === false ? '' : substr($class, 0, $pos);
        $name = $pos === false ? $class : substr($class, $pos + 1);
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $name) !== 1) {
            $name = self::degenerate($name);
        }
        return [$ns, $name];
    }

    /** PHP literal for attribute arguments; arrays render inline. */
    private static function export(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            return '[' . implode(', ', array_map(self::export(...), $v)) . ']';
        }
        return var_export($v, true);
    }
}
