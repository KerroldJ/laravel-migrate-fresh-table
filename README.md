# Laravel Migrate Fresh Table

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

> `migrate:fresh`, but scoped to **one table** (or a chosen, ordered set) instead of wiping the whole database.

Sometimes you need to rebuild a single table from scratch — reset a corrupted
pivot, reapply a changed schema during development, or re-provision one table per
tenant — without nuking everything else. This package does exactly that, safely:

- 🎯 **Scoped** — drop & recreate one table or an ordered list, not the whole DB.
- 🔗 **Foreign-key aware** — inspects the **live** schema, reports every parent and
  child relationship with the rows that would be orphaned, and asks first.
- 🌊 **Cascade or data-only** — `--with-related` freshes a table plus its whole
  dependent tree; `--data-only` empties the rows and keeps the schema.
- 🧩 **Pluggable** — choose how a table is rebuilt: re-run its migration, or
  rebuild from an explicit `Blueprint`. Register your own strategy too.
- 🏢 **Multi-connection / multitenant** — target one connection, iterate a list, or
  resolve tenant connections dynamically. PostgreSQL `search_path` aware.
- 🧪 **Safe by default** — confirms before running in protected environments
  (configurable), plus `--dry-run`, `--pretend`, and a **transaction** so a
  failed run rolls back instead of half-dropping.
- 🪝 **Hookable** — lifecycle events and global before/after callbacks.

Works across **MySQL/MariaDB, PostgreSQL, SQLite and SQL Server** on **Laravel 12+**
and **PHP 8.2+**.

---

## Installation

```bash
composer require kerroldj/laravel-migrate-fresh-table
```

The service provider is auto-discovered. Publish the config file:

```bash
php artisan vendor:publish --tag=migrate-fresh-table-config
```

This creates `config/migrate-fresh-table.php`.

---

## Quick start

```bash
# Fresh a single table (auto-detects the migration that creates it)
php artisan migrate:fresh-table users

# Fresh several tables in a safe, dependency-aware order (parents first)
php artisan migrate:fresh-table --tables=users,posts,comments

# Fresh a table AND every table that references it (the full dependent tree)
php artisan migrate:fresh-table users --with-related

# Just empty the data — keep the schema, drop nothing
php artisan migrate:fresh-table users --with-related --data-only

# Re-seed afterwards
php artisan migrate:fresh-table posts --seed --seeder="Database\Seeders\PostSeeder"

# See exactly what would happen, change nothing
php artisan migrate:fresh-table users --dry-run

# Print the SQL that would run
php artisan migrate:fresh-table users --pretend
```

---

## How it works

For each target table the command:

1. **Inspects the live schema** on the resolved connection to find every foreign
   key that touches the table — both the parents it references and the children
   that reference it.
2. **Prints a foreign-key impact report** (always).
3. **Asks what to do** (unless `--force`, `--dry-run`, or `--pretend`) — when
   dependents exist you can fresh only the target, or cascade to its dependents.
4. **Drops** the target table(s) — children first — detaching any foreign keys
   that block the drop, then **recreates** them — parents first — using the
   selected strategy, and restores constraints. On PostgreSQL and SQL Server the
   whole drop/recreate runs in a **transaction**, so a failure rolls everything
   back instead of leaving the database half-dropped.
5. Optionally **re-seeds**.

Drop/recreate ordering is dependency-safe: tables are dropped children-first and
recreated parents-first. (With `--tables=a,b,c` you supply the order yourself —
`a` is a parent of `b` is a parent of `c`.)

### The foreign-key impact report & prompt

```
  Foreign-key impact report
  Rows = dependent rows that reference the target via this FK (not null) — what a fresh would orphan.
  users
    references (re-created, not modified): countries, roles
+----------------+-------------------------------+------+
| Related table  | Relationship                  | Rows |
+----------------+-------------------------------+------+
| comments       | comments.user_id -> users.id  | 12   |
| posts          | posts.user_id -> users.id     | 134  |
+----------------+-------------------------------+------+

  Plan
    connection: mysql
    drop order:    users
    recreate order: users
      users: strategy migration → 2014_10_12_000000_create_users_table.php

 The above tables are affected by foreign keys. What would you like to do?
  [0] Fresh only users
  [1] Fresh users and all tables that reference it
  [2] Cancel
```

- The table lists every **dependent** that references the target, A–Z, with the
  **number of rows that actually point at it** (FK not null) — the rows a fresh
  would orphan. Parent tables the target *references* are shown separately and
  are **not** modified.
- When dependents exist you get the three-way choice above; picking **[1]** is
  the same as `--with-related`. With no dependents it's a simple yes/no.
- In a **protected environment** (default `production`) you are first asked
  *“Do you really wish to run this command?”*; other environments skip straight
  to the foreign-key prompt. See [Safety notes](#safety-notes).
- **`--force`** skips every prompt (and is required to run non-interactively in a
  protected environment). **`--dry-run`** prints the report and plan, then stops.
  **`--pretend`** prints the SQL without running it. The report is recomputed
  **per connection / tenant**.

---

## Freshing dependent tables (`--with-related`)

By default only the table(s) you name are freshed; tables that reference them
keep their rows, which then point at records that no longer exist. Pass
**`--with-related`** to also fresh every table that (transitively) references the
target, in dependency-safe order:

```bash
php artisan migrate:fresh-table users --with-related

# Non-interactive (e.g. CI) — required to skip the prompt:
php artisan migrate:fresh-table users --with-related --force
```

The command resolves the full dependency **closure**, drops children-first and
recreates parents-first, detaching and restoring foreign keys as needed —
including **circular** references. On PostgreSQL and SQL Server the whole run is
wrapped in a **transaction**, so any failure rolls everything back rather than
leaving the database half-dropped.

> A cascade can touch a lot of tables and **wipes their data**. Preview with
> `--dry-run` first and take a backup.

If one migration file creates several tables (e.g. `users` +
`password_reset_tokens` + `sessions`), the command treats the **migration** as
the unit: it drops those sibling tables together and re-runs the file once, so
recreation never collides with a leftover table.

---

## Clearing data only (`--data-only`)

Sometimes you don't want to rebuild structure at all — you just want to **empty**
the tables. `--data-only` deletes rows instead of dropping/recreating, leaving
every table, column, index and constraint exactly in place:

```bash
# Empty users and everything that depends on it, keeping all schema
php artisan migrate:fresh-table users --with-related --data-only

# Preview (nothing is deleted)
php artisan migrate:fresh-table users --with-related --data-only --dry-run
```

Rows are deleted **children-first** (so no foreign key is violated) inside a
transaction. Because it never drops a table or re-runs a migration, `--data-only`
works cleanly even on schemas with bundled migrations, circular foreign keys, or
columns added by later `ALTER` migrations — cases where a structural
drop/recreate is hard or impossible.

| | drop/recreate (default) | `--data-only` |
| --- | --- | --- |
| Table structure | rebuilt from its migration | left untouched |
| Re-runs migrations | yes | never |
| Clears data | yes | yes |
| Use when | you need to rebuild the schema | you just want to wipe the data |

---

## Strategies (customizable rebuild logic)

How a table is recreated after it's dropped is decided by a **strategy**. Two ship
out of the box, and you can register your own.

### 1. `migration` strategy (default)

Re-runs the migration(s) that build the table. It auto-detects the owning
migration by scanning your migration paths for `Schema::create('<table>', …)`. If
auto-detection isn't reliable for a table, add a manual override:

```php
// config/migrate-fresh-table.php
'overrides' => [
    'users' => [
        'database/migrations/2014_10_12_000000_create_users_table.php',
    ],
],
```

### 2. `schema` strategy

Recreates the table from an explicit `Blueprint` callback — handy when no single
migration cleanly owns a table:

```php
use Illuminate\Database\Schema\Blueprint;

// config/migrate-fresh-table.php
'schema' => [
    'sessions' => function (Blueprint $table) {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('payload');
        $table->integer('last_activity')->index();
    },
],
```

Select it per run or per table:

```bash
php artisan migrate:fresh-table sessions --strategy=schema
```

```php
'table_strategies' => [
    'sessions' => 'schema',
],
```

### 3. Your own strategy

Implement the contract and register it:

```php
use Kerroldj\MigrateFreshTable\Contracts\FreshStrategy;
use Kerroldj\MigrateFreshTable\Support\FreshContext;

class ParquetImportStrategy implements FreshStrategy
{
    public function name(): string
    {
        return 'parquet';
    }

    public function recreate(FreshContext $context): void
    {
        // $context->table, $context->connection, $context->schema,
        // $context->schemaBuilder, $context->pretend, $context->options
        $context->schemaBuilder->create($context->table, function ($table) {
            // ...
        });
    }
}
```

```php
// config/migrate-fresh-table.php
'strategies' => [
    'migration' => \Kerroldj\MigrateFreshTable\Strategies\MigrationStrategy::class,
    'schema'    => \Kerroldj\MigrateFreshTable\Strategies\SchemaStrategy::class,
    'parquet'   => \App\Fresh\ParquetImportStrategy::class,
],
```

```bash
php artisan migrate:fresh-table events --strategy=parquet
```

Custom strategies are resolved through the container, so you may type-hint
dependencies. You can also swap the migration resolver itself by binding
`Kerroldj\MigrateFreshTable\Contracts\TableResolver`.

---

## Multi-connection & multitenancy

```bash
# Target a specific connection
php artisan migrate:fresh-table users --connection=tenant_42

# --database is an accepted alias
php artisan migrate:fresh-table users --database=tenant_42

# Run across every configured connection / tenant in one call
php artisan migrate:fresh-table users --all-connections --force
```

`--all-connections` iterates the static list **plus** anything returned by a
dynamic tenant resolver:

```php
// config/migrate-fresh-table.php
'connections' => ['tenant_one', 'tenant_two'],

'tenant_resolver' => fn () => \App\Models\Tenant::query()->pluck('connection')->all(),
```

The resolved connection is always used explicitly — the package never assumes the
`default` connection — and the foreign-key report runs independently per
connection.

### PostgreSQL schema awareness

```bash
# Fresh the same table within a specific schema / search_path
php artisan migrate:fresh-table audit_log --connection=pgsql --schema=reporting
```

The `search_path` is set for the duration of the run and restored afterward, so
the same table name in different schemas can be freshed independently.

---

## Command reference

```
migrate:fresh-table {table?}

Arguments:
  table                  The table to fresh.

Options:
  --tables=              Comma-separated, ordered list of tables (parents first).
  --with-related         Also fresh every table that references the target (cascade).
  --data-only            Delete rows instead of dropping/recreating (keep schema).
  --strategy=            Resolution strategy (migration|schema|custom).
  --connection=          The database connection to operate on.
  --database=            Alias for --connection.
  --all-connections      Iterate every configured connection / tenant.
  --schema=              PostgreSQL schema (search_path) to operate within.
  --seed                 Re-seed after recreating.
  --seeder=              The seeder class to run.
  --force                Skip all prompts; required to run non-interactively in a protected env.
  --dry-run              Print the impact report and plan, then stop.
  --pretend              Print the SQL that would run, without executing.
```

---

## Events & hooks

Listen to lifecycle events (each carries `connection`, `table`, `schema`):

| Event | Fired |
| --- | --- |
| `TableFreshing` | before a table is freshed |
| `TableDropping` / `TableDropped` | around the drop |
| `TableRecreating` / `TableRecreated` | around the recreate |
| `TableFreshed` | after a table is freshed |

```php
use Kerroldj\MigrateFreshTable\Events\TableFreshed;

Event::listen(TableFreshed::class, function (TableFreshed $event) {
    logger()->info("Freshed {$event->table} on {$event->connection}");
});
```

Or use global callbacks, fired once per connection:

```php
// config/migrate-fresh-table.php
'hooks' => [
    'before' => function (string $connection, array $tables) { /* ... */ },
    'after'  => function (string $connection, array $tables) { /* ... */ },
],
```

---

## Safety notes

- **Protected environments** (by default `production`) ask you to confirm before
  anything is dropped — an interactive run prints
  `Application is in the [production] environment.` then asks
  *“Do you really wish to run this command?”*. In any other environment
  (e.g. `local`) it runs without that question. Configure the list in
  `config/migrate-fresh-table.php`:

  ```php
  // Which APP_ENV values require confirmation. Wildcards (e.g. "prod*") work.
  'protected_environments' => ['production'],
  ```

- `--force` skips the confirmation, and is **required** to run non-interactively
  (e.g. in CI) within a protected environment — otherwise the command refuses.
- `--dry-run` and `--pretend` are always allowed since they never mutate.
- The **foreign-key impact report is always printed**, and you are **always
  prompted** in interactive mode unless `--force`.
- If a migration can't be resolved, the command fails loudly with a message
  telling you to add an `overrides` entry or switch the table to the `schema`
  strategy.

---

## Testing

```bash
composer test          # Pest
composer analyse       # PHPStan / Larastan
composer format        # Laravel Pint
```

The suite defaults to an in-memory SQLite database. Because every developer has
a different local setup, it also reads a `.env` from the package root — copy the
example and point it at your database:

```bash
cp .env.example .env
# edit .env (DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
vendor/bin/pest
```

`DB_CONNECTION` accepts `sqlite`, `mysql`, `mariadb`, `pgsql`, or `sqlsrv`
(`postgres`/`postgresql` and `mssql`/`sqlserver` work as aliases). Real
environment variables override `.env`, so a one-off run is also fine:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=laravel_test \
DB_USERNAME=postgres DB_PASSWORD=secret vendor/bin/pest
```

---

## License

The MIT License (MIT). See [LICENSE](LICENSE).
