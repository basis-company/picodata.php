# picodata.php
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![CI](https://github.com/basis-company/picodata.php/actions/workflows/ci.yml/badge.svg)](https://github.com/basis-company/picodata.php/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/github/release/basis-company/picodata.php.svg)](https://github.com/basis-company/picodata.php/releases)

Picodata client for PHP: connection and raw SQL, schema reading and migration, typed-class hydration, change subscriptions.

Picodata is a PostgreSQL-compatible distributed in-memory SQL DB (instances / replicasets / tiers / buckets, engines `memtx`/`vinyl`, global and sharded tables). This package keeps Picodata terminology as-is: `engine`, `distribution`, `tier`, `global`, `_pico_table` introspection.

## Install

```bash
composer require basis-company/picodata
```

Requires PHP 8.4+ and `ext-pgsql` for the bundled transport (Picodata speaks the PostgreSQL wire protocol on its pg endpoint).

## Connect

```php
use Basis\Picodata\Picodata;

$db = Picodata::connect("postgresql://app:secret@127.0.0.1:5432/picodata");

// raw SQL — always available, full Picodata dialect
$rows = $db->query('SELECT * FROM auth_user WHERE active = ?', [1])->all();
$affected = $db->execute('DELETE FROM session WHERE expired_at < CURRENT_TIMESTAMP');

$db->transaction(function (Picodata $db) {
    $db->execute('UPDATE auth_user SET active = ? WHERE id = ?', [0, 42]);
    $db->execute('DELETE FROM session WHERE user_id = ?', [42]);
});
```

> **Transactions.** Picodata's pg wire autocommits every statement, so
> `transaction()` is a no-op wrapper: it runs the callable and lets its value or
> exception pass through, but emits no `BEGIN`/`COMMIT`/savepoint and cannot roll
> back. It exists so code written transactionally still runs; treat each statement
> inside as already committed. This matches the official Picodata drivers.

**DSN.** Pass a libpq URI (`postgresql://user:pass@host:5432/db`); the keyword form (`host=… user=…`) is rejected by Picodata's pgwire — details under **Development & testing**.

## Schema

Everything the cluster knows about its own tables is one call away, and the library can keep a declared schema in sync with it. Read first — a cluster may already hold tables this program never created; then declare your own. Entity classes, once you write them, slot into the very same methods.

### Discovery

`exists()` answers whether a table is there. `load()` reads the cluster's **live** schema (`_pico_table` + `_pico_index`) into a `Schema\Table` — it works for any table, including ones this program never created — and registers it, so the table is immediately queryable by name (`$db->get('auth_user', 1)`, see **Fetch** below):

```php
$schema = $db->schema();

$schema->exists('auth_user');
$table = $schema->load('auth_user');

$table->columns;         // name to Column map: ->type, ->nullable, ->unsigned, ->array, ->primary, ->default
$table->indexes;         // Index list: ->name, ->columns, ->using (TREE/HASH/RTREE/BITSET), ->unique
$table->primary;         // list of column names
$table->engine;          // 'memtx' or 'vinyl'
$table->tier;            // tier name or null
$table->phpType('quota');// the PHP type this column maps to

$file = $schema->generateClasses('auth_user', 'App\\Model\\AuthUser', __DIR__ . '/src/Model'); // entity class from the live schema
```

`Schema\Table`/`Column`/`Index` are plain metadata objects: `Table->columns` is a name-keyed map (`->indexes`, `->primary`, `->engine`, `->tier` are plain properties beside it), and `Column` spells its SQL scalar types as constants — `Column::INTEGER`, `Column::TEXT`, `Column::DOUBLE`, `Column::BOOLEAN`, `Column::DATETIME`, `Column::UUID`, `Column::DECIMAL`, `Column::JSON` — with `nullable`/`unsigned`/`array` as flags. `Schema\Ddl::createTable()`/`createIndex()`/`addColumn()` return the SQL if you want to review or hand-edit it.

### Your own schema (Model)

When the shape isn't a class you want to keep around — dynamic, tenant- or plugin-owned schemas — describe the table with `Schema\Model`, no entity class required. The table name is verbatim: what you call it is what it is.

```php
use Basis\Picodata\Schema\Column;
use Basis\Picodata\Schema\Model;

$orders = Model::define('orders')
    ->engine('vinyl')
    ->tier('hot')
    ->column('id', Column::INTEGER, primary: true)
    ->column('username', Column::TEXT)
    ->column('total', Column::DOUBLE, nullable: true)
    ->index(['username']);

$sql = $schema->create($orders);  // the executed SQL: CREATE TABLE orders USING vinyl DISTRIBUTED BY (id) IN TIER hot, then its CREATE INDEX ...
$rows = $db->find($orders, ['username' => 'bob']);  // rows hydrate into a generated typed class (see Fetch below)
```

A `Model` is a lazy `Schema\Table` builder: `toTable()` returns the metadata object, `register()` puts it in the class factory (the name-to-class registry used when querying by plain table name, see **Dynamic classes**). `$schema->create($orders)` / `$schema->drop($orders)` are not special to a `Model`: everywhere it is accepted, a bare `Schema\Table` is accepted the same way — and, further down, an entity class.

### Migrate

Grow the definition by one `column()`/`index()` (later, by one property on an entity class), then run `migrate()` on deploy. It creates the table when it does not exist yet, adds columns the definition grew, and creates missing indexes; it returns the SQL it actually executed, and an empty array once everything is current:

```php
$orders->column('note', Column::TEXT, nullable: true);   // the definition grew a column

$applied = $schema->migrate($orders);
// ALTER TABLE orders ADD COLUMN IF NOT EXISTS note TEXT

foreach ($schema->diff($orders)->issues as $issue) {
    echo $issue;   // drift migrate() cannot fix
}
```

`diff()` returns the same `SchemaDiff` without executing anything: `missingTable`, `addColumns`, `addIndexes`, `issues`, `isEmpty()` — a preview for deploy logs and release gates. Indexes are matched by name; an index without an explicit name is recognised by its generated `{table}_{cols}_idx` name, so give indexes stable names when their columns may change.

What `migrate()` never does is exactly what Picodata's `ALTER TABLE` cannot do; such drift is reported in `issues` for a human:

| Drift | Resolution |
| --- | --- |
| Column type, nullability, engine, primary key, distribution, tier | Immutable in place: create a new table, backfill, `drop()` the old one |
| A column removed from code | Picodata has no `DROP COLUMN`; if it must go, `ALTER TABLE ... RENAME COLUMN` it out of the way and `load()` again |
| An index that exists live but differs or is obsolete | Fix by hand with `$schema->dropIndex()` / `$schema->createIndex()` |

A UNIQUE index on a sharded table must start with the sharding key; Picodata rejects it at `create()`/`migrate()` time otherwise.

Generated SQL uses `?` placeholders; values are always sent as query parameters (never interpolated). Raw SQL may use `?` or `:name` — they are rewritten to `$1..$n` for the wire.

## Entity = typed class

A class is a table: every promoted constructor property is a column, its SQL type and nullability come from the PHP type, the table name is the snake_case class name. Where a default doesn't match what Picodata docs prescribe for your table, hang the corresponding single-purpose attribute on the class — one attribute per CREATE TABLE clause.

```php
use Basis\Picodata\Attribute\Engine;
use Basis\Picodata\Attribute\TableName;
use Basis\Picodata\Attribute\Tier;
use Basis\Picodata\Attribute\DistributedGlobally;
use Basis\Picodata\Indexing;
use Basis\Picodata\Schema\Index;

#[TableName('auth_user')]         // CREATE TABLE auth_user (name differs from class → stated)
#[Engine('vinyl')]               // USING vinyl
#[Tier('hot')]                   // IN TIER "hot"
final class User implements Indexing
{
    public function __construct(
        public int $id,                        // INTEGER PRIMARY KEY (first param)
        public string $username,               // TEXT NOT NULL
        public ?DateTimeImmutable $created_at = null,  // DATETIME NULL
    ) {}

    // CREATE [UNIQUE] INDEX ... ON ... USING {TREE|HASH|RTREE|BITSET} (cols)
    public static function indexes(): array
    {
        return [
            // on a sharded table a UNIQUE index must carry the sharding key (the PK) as a prefix
            new Index(columns: ['id', 'username'], unique: true),
            new Index(columns: ['created_at', 'username']),
        ];
    }
}

#[DistributedGlobally]           // DISTRIBUTED GLOBALLY
final class Config               // table name is just "config" — no attribute needed
{
    public function __construct(
        public string $key,
        public string $value,
    ) {}
}
```

Docs to PHP, one-to-one:

| Picodata (docs)              | PHP                                            |
|------------------------------|------------------------------------------------|
| `CREATE TABLE auth_user`      | class name / `#[TableName('auth_user')]` only when different |
| `UNLOGGED TABLE`             | `#[Unlogged]`                                  |
| `USING memtx\|vinyl`         | `#[Engine('vinyl')]` (default memtx)           |
| `DISTRIBUTED BY (cols)`      | `#[Distributed(['tenant_id'])]` (default: PK)  |
| `DISTRIBUTED GLOBALLY`       | `#[DistributedGlobally]` (requires memtx)      |
| `IN TIER "hot"`              | `#[Tier('hot')]`                               |
| `PRIMARY KEY (cols)`         | first ctor param / `#[PrimaryKey(['a','b'])]`  |
| column `TYPE NOT NULL`       | promoted property type (PHP `int` is INTEGER, `?string` is TEXT NULL, …) |
| `CREATE [UNIQUE] INDEX`      | `Indexing::indexes()` returning `Schema\Index` (name auto-generated) |

`#[Column(name:, type:, unsigned:, array:)]` stays as the narrow escape hatch for what PHP types can't express (exact column rename, `INTEGER UNSIGNED`, real `TEXT ARRAY` columns — a plain PHP `array` maps to TEXT with JSON encoding). Defaults are never annotated: no attribute = memtx, PK = first param, distributed = PK, name from class.

That is where the classes meet the schema: everywhere a `Model` was accepted above, a class name works the same way — `$schema->create(User::class)` builds the table, `$schema->migrate(User::class)` keeps it current.

### Naming: no schemas in Picodata

Picodata has **no namespaces/schemas** — table names are flat and unique cluster-wide (allowed chars: letters, digits not first, `-`, `_`; `CREATE SCHEMA` does not exist, `_pico_table.name` is a unique string). Two entities named `User` in different app domains collide, exactly like Tarantool spaces. Resolve it client-side with a **Resolver** — the naming rule that turns a class into a table name:

```php
use Basis\Picodata\Map\PrefixedResolver;

// namespace-aware: map each domain namespace to its own table prefix
$db = Picodata::connect($dsn, resolver: new PrefixedResolver([
    'App\\Auth\\'    => 'auth_',     // App\Auth\User    resolves to auth_user
    'App\\Billing\\' => 'billing_',  // App\Billing\User resolves to billing_user
], default: ''));
```

The rule is bidirectional. `tableOf(class)` names the table on reads and writes; `classOf(table)` maps a live table back to its class when you query by table name — and where no loadable class is found, the typed class is generated at runtime instead. A bare `default` prefix (no map) reproduces the old single-prefix behaviour.

`#[TableName('...')]` on a class always overrides the rule for that one class:

```php
#[TableName('legacy_users')]   // wins over any namespace prefix
final class User { /* ... */ }
```

`Resolver` is an interface (`tableOf` / `classOf`) — implement your own to hash names, read a manifest, or match a legacy convention; `PrefixedResolver` is just the shipped default.

## Fetch

```php
$users  = $db->find(User::class, ['active' => 1]);              // list<User>
$user   = $db->findOne(User::class, ['username' => 'nekufa']);  // ?User
$user   = $db->findOrFail('auth_user', ['username' => 'guest']); // object or NotFoundException
$user   = $db->get(User::class, 42);                            // by primary key, ?User
$new    = $db->findOrCreate('user', ['username' => 'nekufa'], ['active' => 1]);

// where: 'col' => value | null (IS NULL) | [v1, v2] (IN) | ['>=' => 18]
$recent = $db->find(User::class, ['age' => ['>=' => 18]], [
    'order'  => ['id' => 'DESC'],
    'limit'  => 10,
    'offset' => 0,
    'columns' => ['id', 'username'],
]);
```

A plain table name works everywhere a class is accepted: the table is introspected from `_pico_table` and the typed class is generated automatically (attributes + promoted constructor), so the result is still an object with typed properties.

## Identity map

Within one connection, a row maps to one object: re-fetching a row already held refreshes the **same instance** (non-readonly properties are updated from the row) instead of hydrating a clone.

```php
$user = $db->findOne(User::class, ['id' => 42]);
$same = $db->get(User::class, 42);   // assertSame($user, $same)

$db->clearIdentity();                // next fetch hydrates fresh objects
```

## Write

```php
$db->insert(User::class, ['id' => 1, 'username' => 'nekufa']); // returns the inserted User
$db->update(User::class, 42, ['username' => 'nekufa']);         // by PK, returns affected rows
$db->update($user, ['username' => 'neku']);                     // object target + PK from it
$db->delete(User::class, 42);
$db->save($user);                                               // insert or update by PK
```

## Dynamic classes

Classes for tables you never wrote by hand are generated at runtime. By default they are `eval`-defined per process; set a path once to **materialize** them as PHP files (written atomically, then required — useful for debuggers, opcache and `class_exists` autoloading):

```php
Basis\Picodata\Map\ClassFactory::$materializePath = __DIR__ . '/src/Runtime';

$order = $db->get('order', 7);   // class Basis\Picodata\Runtime\Order now lives in src/Runtime/Order.php
```

For checked-in models use `$db->schema()->generateClasses('order', 'App\\Model\\Order', __DIR__ . '/src/Model')` instead — same generator, your namespace and path.

## Change journal

Change capture with subscriptions: the journal row is written immediately after the data change and wrapped in `BEGIN`/`COMMIT` by the library, so on a transactional backend the change and its row commit together (never lost, never phantom). Until you register a listener, writes pay zero overhead. Note that Picodata's pg wire autocommits, so there the two statements commit independently — treat delivery as *at-least-once*, deduplicating on `Change::$id`.

```php
$db->changes()->register('auth_user', 'queue-worker');   // lazily creates picodata_change / picodata_subscription
$db->changes()->setContext(fn() => ['request_id' => $reqId]);

$user = $db->get('auth_user', 42);
$db->update($user, ['locked' => time()]);                // journaled: BEGIN; UPDATE; INSERT picodata_change; COMMIT (each statement autocommits on Picodata)

foreach ($db->changes()->get('queue-worker', limit: 100) as $change) {
    handle($change->tablename, $change->action, $change->data, $change->context);
    $db->changes()->ack([$change->id]);                  // delete consumed rows
}
```

A subscription's table name may also be a pattern: `'*'` covers every table in the cluster, and fnmatch globs (`'log_*'`, case-insensitive) cover a family of tables. Each data change writes one journal row per matching listener, so a broad subscription never hides changes from a narrow one — `get()` consumes only its own listener's rows:

```php
$db->changes()->register('*', 'audit');         // journal every write in the cluster
$db->changes()->register('log_*', 'log-tail');  // glob subscriptions work the same way
```

## Development & testing

Unit tests run against fakes and need no server:

```
composer install
vendor/bin/phpunit --testsuite unit
```

Integration tests run against a live two-node cluster on the compose network
(hosts `picodata-1-1` / `picodata-1-2`, pg wire on `5432`, `admin` / `T0psecret`).
They skip themselves when the cluster is unreachable or when `pg_connect()`
fails, so the default suite stays green anywhere:

```
docker compose up -d
docker compose run --rm phpunit --testsuite integration
```

Code style is PSR-12, enforced by php-cs-fixer: `composer cs` rewrites the
sources, CI gates them with `--dry-run`.

```
vendor/bin/php-cs-fixer fix --dry-run --diff
```

GitHub Actions (`.github/workflows/ci.yml`) runs all three: the unit matrix
(PHP 8.4 / 8.5), the style check, and the integration suite against a two-node
`docker compose` cluster.

`docker-compose.yml` boots the nodes from the official `picodata run -c …` image
with cluster options passed on the command line (no config file is mounted). Both
nodes are `tier=default` plus `tier=hot`, each with `replication_factor: 1`, so
`vshard` bootstraps immediately on two nodes and `IN TIER hot` writes land on node
2. Data lives in named volumes; no host volume is published and no ports are
exposed — the `phpunit` service reaches the nodes over the compose network only.

Two Picodata specifics the suite depends on, both documented in the code:

- **DSN form.** Pass a libpq **URI** (`postgresql://admin:…@host:5432/…`), not the
  `host=… user=…` keyword form. Keyword-form `StartupMessage` is rejected by the
  current pgwire verifier with `28P01`, so `Picodata::connect` expects a URI. The
  library forwards your DSN to `pg_connect` verbatim; it never rewrites it.
- **Autocommit only.** The pgwire interface autocommits every statement, so
  `Picodata::transaction()` runs the callable with no `BEGIN`/`COMMIT`/savepoints
  and writes commit as they execute. See the note under **Connect** above.

## License

MIT
