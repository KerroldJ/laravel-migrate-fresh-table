<?php

declare(strict_types=1);

use Kerroldj\MigrateFreshTable\Strategies\MigrationStrategy;
use Kerroldj\MigrateFreshTable\Strategies\SchemaStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Protected Environments
    |--------------------------------------------------------------------------
    |
    | Environments where freshing a table is dangerous enough to require an
    | explicit confirmation before anything is dropped. The current value of
    | APP_ENV (app()->environment()) is matched against this list.
    |
    |   - In a protected environment the command interactively asks
    |     "Do you really wish to run this command?" before proceeding.
    |     Passing --force skips the prompt; a non-interactive run without
    |     --force is refused outright.
    |   - In any other environment (e.g. "local") it runs without asking.
    |
    | Wildcards are supported (e.g. "prod*") since matching uses the same
    | rules as app()->environment().
    |
    */

    'protected_environments' => ['production'],

    /*
    |--------------------------------------------------------------------------
    | Default Resolution Strategy
    |--------------------------------------------------------------------------
    |
    | When a table is freshed the package needs to know HOW to re-create it.
    | The default strategy is used unless overridden per-run with --strategy=
    | or per-table in the "table_strategies" map below.
    |
    | Shipped strategies:
    |   - "migration" : re-run the migration(s) that create the table.
    |   - "schema"    : re-create the table from an explicit Blueprint callback.
    |
    */

    'default_strategy' => 'migration',

    /*
    |--------------------------------------------------------------------------
    | Strategy Registry
    |--------------------------------------------------------------------------
    |
    | Map a strategy name to a class implementing the FreshStrategy contract.
    | Register your own here, then select it with --strategy=my-strategy or by
    | binding the class in the container. Custom classes are resolved through
    | the service container, so you may type-hint dependencies freely.
    |
    */

    'strategies' => [
        'migration' => MigrationStrategy::class,
        'schema' => SchemaStrategy::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-table Strategy Overrides
    |--------------------------------------------------------------------------
    |
    | Force a specific strategy for specific tables, e.g. when one table has no
    | single owning migration and must be rebuilt from a Blueprint.
    |
    |   'sessions' => 'schema',
    |
    */

    'table_strategies' => [
        // 'sessions' => 'schema',
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Discovery Paths
    |--------------------------------------------------------------------------
    |
    | Directories scanned by the "migration" strategy when auto-detecting the
    | migration that owns a table (by parsing Schema::create()/Schema::table()).
    |
    */

    'migration_paths' => [
        database_path('migrations'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual Migration Overrides (fallback for auto-detection)
    |--------------------------------------------------------------------------
    |
    | Map a table name to the migration file(s) that build it. Paths may be
    | absolute or relative to the application base path. Use this when the
    | parser cannot reliably resolve the owning migration. Files run in the
    | order listed.
    |
    |   'users' => [
    |       'database/migrations/2014_10_12_000000_create_users_table.php',
    |   ],
    |
    */

    'overrides' => [
        // 'users' => ['database/migrations/2014_10_12_000000_create_users_table.php'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Strategy Definitions
    |--------------------------------------------------------------------------
    |
    | Explicit Blueprint definitions used by the "schema" strategy. Each entry
    | is a callable receiving an Illuminate\Database\Schema\Blueprint instance,
    | exactly as you would write inside Schema::create().
    |
    */

    'schema' => [
        // 'sessions' => function (Blueprint $table) {
        //     $table->string('id')->primary();
        //     $table->foreignId('user_id')->nullable()->index();
        //     $table->string('ip_address', 45)->nullable();
        //     $table->text('payload');
        //     $table->integer('last_activity')->index();
        // },
    ],

    /*
    |--------------------------------------------------------------------------
    | Connections (multi-connection / multitenancy)
    |--------------------------------------------------------------------------
    |
    | A static list of connection names iterated when --all-connections is
    | passed. For dynamic tenant databases, supply a "tenant_resolver" below.
    |
    */

    'connections' => [
        // 'tenant_one',
        // 'tenant_two',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolver
    |--------------------------------------------------------------------------
    |
    | An optional callable returning an iterable of connection names. Useful
    | for multitenancy where tenant connections are registered at runtime.
    | When set, it is used (in addition to the static list) by --all-connections.
    |
    |   'tenant_resolver' => fn () => \App\Models\Tenant::pluck('connection'),
    |
    */

    'tenant_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Global Hooks
    |--------------------------------------------------------------------------
    |
    | Callables fired once per connection, before and after the whole
    | drop/recreate run. Each receives the connection name and the list of
    | tables. For per-table hooks, listen to the dispatched events instead.
    |
    |   'hooks' => [
    |       'before' => function (string $connection, array $tables) {},
    |       'after'  => function (string $connection, array $tables) {},
    |   ],
    |
    */

    'hooks' => [
        'before' => null,
        'after' => null,
    ],

];
