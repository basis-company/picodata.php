<?php

declare(strict_types=1);

namespace Basis\Picodata\Map;

/**
 * Default {@see Resolver}: a namespace -> table-prefix map with longest-prefix
 * matching and a fallback default prefix.
 *
 * Modular apps give each domain namespace a physical prefix, so entities with
 * the same short class name stop colliding:
 *
 *     new PrefixedResolver([
 *         'App\\Auth\\'    => 'auth_',
 *         'App\\Billing\\' => 'billing_',
 *     ]);
 *     // App\Auth\User    -> auth_user      (and auth_user -> App\Auth\User)
 *     // App\Billing\User -> billing_user
 *
 * class -> table picks the longest namespace rule the class matches, then
 * appends the snake_case short class name; table -> class reverses the prefix
 * and CamelCases the remainder into that namespace (the caller only uses it
 * when the class actually autoloads).
 *
 * A bare default prefix reproduces the old global tablePrefix behaviour:
 * `new PrefixedResolver(default: 'auth_')` maps every class to auth_<snake>.
 * A `#[TableName]` attribute always overrides the rule.
 */
final class PrefixedResolver implements Resolver
{
    /** @var list<array{0:string,1:string}> [namespace prefix (ends in \), table prefix] */
    private readonly array $rules;

    /**
     * @param array<string,string> $map namespace prefix => table prefix
     * @param string $default table prefix for classes matching no rule
     */
    public function __construct(array $map = [], private readonly string $default = '')
    {
        $rules = [];
        foreach ($map as $namespace => $prefix) {
            $rules[] = [self::namespacePrefix($namespace), (string) $prefix];
        }
        $this->rules = $rules;
    }

    public function tableOf(string $class): string
    {
        return $this->prefixOf($class) . Mapper::snake(self::shortName($class));
    }

    public function classOf(string $table): ?string
    {
        $table = strtolower($table);
        $best = null;
        $bestLen = 0;

        foreach ($this->rules as [$namespace, $prefix]) {
            $prefix = strtolower($prefix);
            if ($prefix === '' || strlen($prefix) <= $bestLen || !str_starts_with($table, $prefix)) {
                continue;
            }
            $rest = substr($table, strlen($prefix));
            $class = Mapper::camel($rest);
            if ($rest === '' || $class === '') {
                continue;
            }
            $best = $namespace . $class;
            $bestLen = strlen($prefix);
        }

        return $best !== null && class_exists($best, true) ? $best : null;
    }

    /** The table prefix for a class: the longest matching namespace rule, else the default. */
    public function prefixOf(string $class): string
    {
        $namespace = self::namespaceOf($class);
        $prefix = $this->default;
        $len = 0;

        foreach ($this->rules as [$rule, $candidate]) {
            if (strlen($rule) > $len && str_starts_with($namespace, $rule)) {
                $prefix = $candidate;
                $len = strlen($rule);
            }
        }

        return $prefix;
    }

    /** Namespace of a class FQCN: every segment up to the short class name (empty for global). */
    private static function namespaceOf(string $fqcn): string
    {
        $fqcn = str_replace('/', '\\', trim($fqcn, '\\'));
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '' : substr($fqcn, 0, $pos + 1);
    }

    /** Normalize a rule key into a namespace prefix: keep every segment, one trailing backslash. */
    private static function namespacePrefix(string $namespace): string
    {
        $namespace = rtrim(str_replace('/', '\\', $namespace), '\\');

        return $namespace === '' ? '' : $namespace . '\\';
    }

    private static function shortName(string $class): string
    {
        $class = trim($class, '\\');
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
