<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Kerroldj\MigrateFreshTable\Contracts\TableResolver;
use Kerroldj\MigrateFreshTable\Events\TableDropped;
use Kerroldj\MigrateFreshTable\Events\TableDropping;
use Kerroldj\MigrateFreshTable\Events\TableFreshed;
use Kerroldj\MigrateFreshTable\Events\TableFreshing;
use Kerroldj\MigrateFreshTable\Events\TableRecreated;
use Kerroldj\MigrateFreshTable\Events\TableRecreating;
use Kerroldj\MigrateFreshTable\Support\ForeignKeyInspector;
use Kerroldj\MigrateFreshTable\Support\ForeignKeyRelation;
use Kerroldj\MigrateFreshTable\Support\FreshContext;
use Kerroldj\MigrateFreshTable\Support\StrategyManager;
use Throwable;

class MigrateFreshTableCommand extends Command
{
    /**
     * Above this many tables, the plan shows a count instead of listing names.
     */
    private const PLAN_DETAIL_LIMIT = 10;

    /**
     * @var string
     */
    protected $signature = 'migrate:fresh-table
        {table? : The table to fresh}
        {--tables= : Comma-separated ordered list of tables (parents first)}
        {--with-related : Also fresh the dependent tables that reference the target}
        {--data-only : Delete rows from the target tables (and dependents) instead of dropping/recreating}
        {--strategy= : Resolution strategy to use (migration|schema|custom)}
        {--connection= : The database connection to operate on}
        {--database= : Alias for --connection}
        {--all-connections : Iterate every configured connection / tenant}
        {--schema= : PostgreSQL schema (search_path) to operate within}
        {--seed : Re-seed after recreating}
        {--seeder= : The seeder class to run}
        {--force : Force the operation to run in production / skip confirmation}
        {--dry-run : Print the affected-table report and plan, then stop}
        {--pretend : Print the SQL that would run, without executing}';

    /**
     * @var string
     */
    protected $description = 'Fresh-migrate a specific table (or set of tables) without wiping the whole database.';

    public function handle(StrategyManager $strategies, Dispatcher $events): int
    {
        $tables = $this->resolveTables();

        if ($tables === []) {
            $this->components->error('No table specified. Pass a table argument or --tables=a,b,c.');

            return self::FAILURE;
        }

        if (! $this->confirmProtectedEnvironment()) {
            return self::FAILURE;
        }

        $connections = $this->resolveConnections();

        foreach ($connections as $connection) {
            $code = $this->processConnection($connection, $tables, $strategies, $events);

            if ($code !== self::SUCCESS) {
                return $code;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $tables
     */
    private function processConnection(
        string $connection,
        array $tables,
        StrategyManager $strategies,
        Dispatcher $events,
    ): int {
        /** @var DatabaseManager $db */
        $db = $this->laravel->make('db');
        $originalDefault = (string) $this->laravel->make('config')->get('database.default');

        // Operate on the resolved connection: make it the default so migrations
        // and Schema calls target it, never an assumed "default".
        $db->setDefaultConnection($connection);

        $conn = $db->connection($connection);
        $schemaName = $this->stringOption('schema');
        $restoreSearchPath = $this->applySchema($conn, $schemaName);

        $this->newLine();
        $this->components->info(sprintf(
            'Connection: %s (%s)%s',
            $connection,
            $conn->getDriverName(),
            $schemaName !== null ? "  schema: {$schemaName}" : '',
        ));

        try {
            $this->reportForeignKeys($conn, $tables);

            // When --with-related is explicit, the plan (and dry-run preview)
            // reflects the full dependency-ordered set that will be freshed.
            $planned = $this->option('with-related')
                ? $this->relatedClosure($conn, $tables)
                : $tables;

            if ($planned !== $tables) {
                $this->printRelatedExpansion($connection, $schemaName, $tables, $planned, $strategies);
            } else {
                $this->printPlan($connection, $schemaName, $tables, $strategies);
            }

            if ($this->option('dry-run')) {
                $this->components->warn('Dry run: nothing was executed.');

                return self::SUCCESS;
            }

            $freshTables = $this->decideFreshSet($conn, $tables, $planned);

            if ($freshTables === null) {
                $this->components->warn('Aborted by user. No changes were made.');

                return self::SUCCESS;
            }

            // An interactive choice may expand beyond what was already planned.
            if ($freshTables !== $tables && $freshTables !== $planned) {
                $this->printRelatedExpansion($connection, $schemaName, $tables, $freshTables, $strategies);
            }

            $this->runHook('before', $connection, $freshTables);

            if ($this->option('pretend')) {
                $this->pretendRun($conn, $freshTables, $strategies, $schemaName);
            } elseif ($this->option('data-only')) {
                $this->executeDataOnly($conn, $connection, $freshTables, $events, $schemaName);
                $this->maybeSeed($connection);
            } else {
                $this->executeRun($conn, $connection, $freshTables, $strategies, $events, $schemaName);
                $this->maybeSeed($connection);
            }

            $this->runHook('after', $connection, $freshTables);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($restoreSearchPath !== null) {
                $restoreSearchPath();
            }

            $db->setDefaultConnection($originalDefault);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $tables
     */
    private function executeRun(
        Connection $conn,
        string $connection,
        array $tables,
        StrategyManager $strategies,
        Dispatcher $events,
        ?string $schemaName,
    ): void {
        // A migration may create several tables in one file (e.g. users +
        // password_reset_tokens + sessions). Re-running it collides with the
        // siblings unless they are dropped too, so fold them into the set.
        $tables = $this->withBundledTables($tables, $strategies);

        if ($this->needsConstraintShuffle($conn)) {
            $this->executeWithConstraintShuffle($conn, $connection, $tables, $strategies, $events, $schemaName);

            return;
        }

        // MySQL/MariaDB and SQLite can drop a referenced table while FK checks
        // are disabled, so ordering plus that switch is enough. (DDL is not
        // transactional on MySQL, so a transaction would not help there.)
        $builder = $conn->getSchemaBuilder();

        $builder->withoutForeignKeyConstraints(function () use (
            $conn, $connection, $tables, $strategies, $events, $schemaName, $builder
        ): void {
            $this->dropTables($builder, $connection, $tables, $events, $schemaName);
            $this->recreateTables($conn, $connection, $tables, $strategies, $events, $schemaName);
        });
    }

    /**
     * Delete the rows from the target tables (and dependents) without touching
     * their schema. Rows are removed children-first so no foreign key is
     * violated, and the whole thing runs in a transaction so a failure (e.g. a
     * still-referenced parent) rolls back rather than partially clearing.
     *
     * @param  list<string>  $tables
     */
    private function executeDataOnly(
        Connection $conn,
        string $connection,
        array $tables,
        Dispatcher $events,
        ?string $schemaName,
    ): void {
        $qualified = $this->qualifiedTableMap($conn, $tables);

        $conn->transaction(function () use ($conn, $connection, $tables, $events, $schemaName, $qualified): void {
            // Children-first (reverse of the parents-first list).
            foreach (array_reverse($tables) as $table) {
                $events->dispatch(new TableFreshing($connection, $table, $schemaName));

                $name = $qualified[$this->bareName($table)] ?? $table;
                $deleted = $conn->table($name)->delete();

                $this->components->twoColumnDetail(
                    "  clear <fg=yellow>{$table}</>",
                    $deleted.' rows deleted',
                );

                $events->dispatch(new TableFreshed($connection, $table, $schemaName));
            }
        });
    }

    /**
     * Append any sibling tables created by the same migration file as a target
     * (migration strategy only), so re-running that file rebuilds them all
     * rather than colliding with the ones left in place.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function withBundledTables(array $tables, StrategyManager $strategies): array
    {
        $resolver = $this->laravel->make(TableResolver::class);
        $result = $tables;
        $seen = array_flip(array_map(fn (string $t): string => $this->bareName($t), $tables));

        foreach ($tables as $table) {
            if ($strategies->strategyNameFor($table, $this->stringOption('strategy')) !== 'migration') {
                continue;
            }

            foreach ($resolver->resolveMigrations($table) as $file) {
                foreach ($this->tablesCreatedBy($file) as $created) {
                    $bare = $this->bareName($created);

                    if (isset($seen[$bare])) {
                        continue;
                    }

                    $seen[$bare] = true;
                    $result[] = $created;
                }
            }
        }

        return $result;
    }

    /**
     * Table names a migration file creates, parsed from its Schema::create()
     * calls (in file order).
     *
     * @return list<string>
     */
    private function tablesCreatedBy(string $file): array
    {
        $contents = @file_get_contents($file);

        if ($contents === false || $contents === '') {
            return [];
        }

        preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches);

        return $matches[1];
    }

    /**
     * PostgreSQL and SQL Server refuse to drop a table while ANY foreign key
     * references it (disabling checks does not help), and an FK cycle has no
     * safe drop order. So detach every FK that points into the drop set first —
     * internal cross-references and cycles included — then drop and recreate,
     * restoring any constraint the recreate did not rebuild. The whole thing
     * runs in a transaction (DDL is transactional on both drivers) so a failure
     * rolls back rather than leaving the database half-dropped.
     *
     * @param  list<string>  $tables
     */
    private function executeWithConstraintShuffle(
        Connection $conn,
        string $connection,
        array $tables,
        StrategyManager $strategies,
        Dispatcher $events,
        ?string $schemaName,
    ): void {
        $builder = $conn->getSchemaBuilder();
        $qualified = $this->qualifiedTableMap($conn, $tables);
        $detached = $this->incomingRelations($conn, $tables);

        $conn->transaction(function () use (
            $conn, $builder, $connection, $tables, $strategies, $events, $schemaName, $detached, $qualified
        ): void {
            foreach ($detached as $relation) {
                $this->dropChildConstraint($builder, $relation, $qualified);
                $this->components->twoColumnDetail(
                    "  drop fk <fg=red>{$relation->constraint}</> on {$relation->localTable}",
                    'detach',
                );
            }

            $this->dropTables($builder, $connection, $tables, $events, $schemaName);
            $this->recreateTables($conn, $connection, $tables, $strategies, $events, $schemaName);

            // Restore any detached FK the recreate did not already rebuild
            // (external children, and constraints added by separate ALTER
            // migrations that the strategy does not run).
            foreach ($detached as $relation) {
                if ($this->constraintExists($conn, $qualified, $relation)) {
                    continue;
                }

                try {
                    $this->addChildConstraint($builder, $relation, $qualified);
                    $this->components->twoColumnDetail(
                        "  restore fk <fg=green>{$relation->constraint}</> on {$relation->localTable}",
                        'done',
                    );
                } catch (Throwable $e) {
                    $this->components->warn(sprintf(
                        '  could not restore fk %s on %s — existing rows violate it. Fresh %s as well, or clean its data.',
                        $relation->constraint,
                        $relation->localTable,
                        $relation->localTable,
                    ));
                }
            }
        });
    }

    /**
     * @param  list<string>  $tables
     */
    private function dropTables(Builder $builder, string $connection, array $tables, Dispatcher $events, ?string $schemaName): void
    {
        // Children-first (reverse of the parents-first list).
        foreach (array_reverse($tables) as $table) {
            $events->dispatch(new TableFreshing($connection, $table, $schemaName));
            $events->dispatch(new TableDropping($connection, $table, $schemaName));
            $builder->dropIfExists($table);
            $this->components->twoColumnDetail("  drop <fg=red>{$table}</>", 'done');
            $events->dispatch(new TableDropped($connection, $table, $schemaName));
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function recreateTables(
        Connection $conn,
        string $connection,
        array $tables,
        StrategyManager $strategies,
        Dispatcher $events,
        ?string $schemaName,
    ): void {
        $resolver = $this->laravel->make(TableResolver::class);
        $ranFiles = [];

        // Parents-first (the given order).
        foreach ($tables as $table) {
            $name = $strategies->strategyNameFor($table, $this->stringOption('strategy'));

            $events->dispatch(new TableRecreating($connection, $table, $schemaName));

            // For the migration strategy, a bundled migration may have already
            // rebuilt this table — running it again would collide. Skip when
            // every owning migration has already run.
            if ($name === 'migration') {
                $files = $resolver->resolveMigrations($table);

                if ($files !== [] && array_diff($files, array_keys($ranFiles)) === []) {
                    $this->components->twoColumnDetail("  recreate <fg=green>{$table}</> via migration", 'bundled');
                    $events->dispatch(new TableRecreated($connection, $table, $schemaName));
                    $events->dispatch(new TableFreshed($connection, $table, $schemaName));

                    continue;
                }
            }

            $strategy = $strategies->make($name);

            $strategy->recreate(new FreshContext(
                table: $table,
                connection: $connection,
                schema: $schemaName,
                schemaBuilder: $conn->getSchemaBuilder(),
                pretend: false,
                options: $this->options(),
            ));

            if ($name === 'migration') {
                foreach ($resolver->resolveMigrations($table) as $file) {
                    $ranFiles[$file] = true;
                }
            }

            $this->components->twoColumnDetail(
                "  recreate <fg=green>{$table}</> via {$strategy->name()}",
                'done',
            );

            $events->dispatch(new TableRecreated($connection, $table, $schemaName));
            $events->dispatch(new TableFreshed($connection, $table, $schemaName));
        }
    }

    private function needsConstraintShuffle(Connection $conn): bool
    {
        return in_array($conn->getDriverName(), ['pgsql', 'sqlsrv'], true);
    }

    /**
     * Every FK that points at a table in the drop set (from any table, inside
     * or outside the set), de-duplicated by table + constraint. Built from a
     * single metadata pass.
     *
     * @param  list<string>  $tables
     * @return list<ForeignKeyRelation>
     */
    private function incomingRelations(Connection $conn, array $tables): array
    {
        $targets = array_map(fn (string $t): string => $this->bareName($t), $tables);
        $inspector = new ForeignKeyInspector($conn->getSchemaBuilder());

        $relations = [];
        $seen = [];

        foreach ($inspector->allRelations() as $relation) {
            if (! in_array($relation->foreignTable, $targets, true)) {
                continue;
            }

            $key = $relation->localTable.'::'.$relation->constraint;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $relations[] = $relation;
        }

        return $relations;
    }

    /**
     * Whether the constraint already exists on the (possibly recreated) table.
     *
     * @param  array<string, string>  $qualified
     */
    private function constraintExists(Connection $conn, array $qualified, ForeignKeyRelation $relation): bool
    {
        $names = array_values(array_unique(array_filter([
            $qualified[$relation->localTable] ?? null,
            $relation->localTable,
        ])));

        foreach ($names as $name) {
            try {
                foreach ($conn->getSchemaBuilder()->getForeignKeys($name) as $fk) {
                    if (($fk['name'] ?? null) === $relation->constraint) {
                        return true;
                    }
                }

                return false;
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $qualified
     */
    private function dropChildConstraint(Builder $builder, ForeignKeyRelation $relation, array $qualified): void
    {
        $table = $qualified[$relation->localTable] ?? $relation->localTable;

        $builder->table($table, function (Blueprint $table) use ($relation): void {
            $table->dropForeign($relation->constraint);
        });
    }

    /**
     * @param  array<string, string>  $qualified
     */
    private function addChildConstraint(Builder $builder, ForeignKeyRelation $relation, array $qualified): void
    {
        $local = $qualified[$relation->localTable] ?? $relation->localTable;
        $foreign = $qualified[$relation->foreignTable] ?? $relation->foreignTable;

        $builder->table($local, function (Blueprint $table) use ($relation, $foreign): void {
            $fk = $table->foreign($relation->localColumns, $relation->constraint)
                ->references($relation->foreignColumns)
                ->on($foreign);

            if ($relation->onDelete !== null) {
                $fk->onDelete($relation->onDelete);
            }

            if ($relation->onUpdate !== null) {
                $fk->onUpdate($relation->onUpdate);
            }
        });
    }

    /**
     * @param  list<string>  $tables
     */
    private function pretendRun(
        Connection $conn,
        array $tables,
        StrategyManager $strategies,
        ?string $schemaName,
    ): void {
        $this->components->info('Pretend: SQL that would run');

        $queries = $conn->pretend(function () use ($conn, $tables, $strategies, $schemaName): void {
            $builder = $conn->getSchemaBuilder();

            foreach (array_reverse($tables) as $table) {
                $builder->dropIfExists($table);
            }

            foreach ($tables as $table) {
                $strategy = $strategies->make($strategies->strategyNameFor($table, $this->stringOption('strategy')));

                $strategy->recreate(new FreshContext(
                    table: $table,
                    connection: (string) $conn->getName(),
                    schema: $schemaName,
                    schemaBuilder: $builder,
                    pretend: true,
                    options: $this->options(),
                ));
            }
        });

        foreach ($queries as $query) {
            $this->line('  <fg=gray>'.$query['query'].'</>');
        }

        if ($queries === []) {
            $this->components->warn('  (no SQL captured)');
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function reportForeignKeys(Connection $conn, array $tables): void
    {
        $inspector = new ForeignKeyInspector($conn->getSchemaBuilder());
        $qualifiedTables = $this->qualifiedTableMap($conn, $tables);
        $any = false;

        $this->newLine();
        $this->line('  <options=bold>Foreign-key impact report</>');
        $this->line('  <fg=gray>Rows = dependent rows that reference the target via this FK (not null) — what a fresh would orphan.</>');

        foreach ($tables as $table) {
            $parents = $inspector->parents($table);
            $children = $inspector->children($table);

            $this->line("  <fg=yellow>{$table}</>");

            if ($parents === [] && $children === []) {
                $this->line('    <fg=gray>no foreign-key relationships</>');

                continue;
            }

            // Parent tables (the ones this table references) are NOT modified by
            // a fresh — they are only the FK targets that get re-created. Show
            // them as a note rather than in the impact table.
            if ($parents !== []) {
                $names = array_keys(array_flip(array_map(static fn ($r) => $r->foreignTable, $parents)));
                sort($names, SORT_STRING | SORT_FLAG_CASE);
                $this->line('    <fg=gray>references (re-created, not modified): '.implode(', ', $names).'</>');
            }

            if ($children === []) {
                $this->line('    <fg=gray>no dependents reference this table — a fresh affects no other table.</>');

                continue;
            }

            $any = true;

            // Dependent tables (the ones that reference this table) ARE affected:
            // their FK constraints are detached/re-attached and their rows may
            // block it. Sort A-Z by table (then relationship) for predictability.
            usort($children, static fn (ForeignKeyRelation $a, ForeignKeyRelation $b): int => [strtolower($a->localTable), $a->describe()]
                <=> [strtolower($b->localTable), $b->describe()]);

            $rows = [];

            foreach ($children as $relation) {
                $rows[] = $this->relationRow($conn, $qualifiedTables, $relation, $relation->localTable);
            }

            $this->table(
                ['Related table', 'Relationship', 'Rows'],
                $rows,
            );
        }

        $this->newLine();

        if (! $any) {
            $this->line('  <fg=gray>No dependent tables reference the target table(s).</>');
        }
    }

    /**
     * Build one impact-report table row, including the related table's current
     * row count (so you can see at a glance which dependents actually hold data).
     *
     * @param  array<string, string>  $qualifiedTables
     * @return list<string>
     */
    private function relationRow(
        Connection $conn,
        array $qualifiedTables,
        ForeignKeyRelation $relation,
        string $relatedTable,
    ): array {
        // Count only the rows that actually reference the target through this
        // FK (its column(s) not null) — not every row in the table.
        $count = $this->countRows($conn, $qualifiedTables, $relatedTable, $relation->localColumns);

        if ($count === null) {
            $rows = '<fg=gray>?</>';
        } elseif ($count > 0) {
            $rows = "<fg=yellow>{$count}</>";
        } else {
            $rows = '<fg=gray>0</>';
        }

        return [
            $relatedTable,
            $relation->describe(),
            $rows,
        ];
    }

    /**
     * Map each bare table name to its schema-qualified name, so operations can
     * target the correct schema (e.g. SQL Server "admin.users", not "dbo.users").
     * Scoped to the relevant schema so that, when one database hosts several
     * schemas with same-named tables, the map never resolves the wrong one.
     *
     * @param  list<string>  $targets
     * @return array<string, string>
     */
    private function qualifiedTableMap(Connection $conn, array $targets): array
    {
        $schema = $this->relevantSchema($conn, $targets);
        $map = [];

        foreach ($conn->getSchemaBuilder()->getTables() as $table) {
            if (! is_array($table)) {
                continue;
            }

            $bare = (string) ($table['name'] ?? '');
            $qualified = (string) ($table['schema_qualified_name'] ?? '');

            if ($bare === '' || $qualified === '') {
                continue;
            }

            if ($schema !== null && (string) ($table['schema'] ?? '') !== $schema) {
                continue;
            }

            $map[$bare] = $qualified;
        }

        return $map;
    }

    /**
     * The schema the run operates within: an explicit --schema, the schema of a
     * qualified target (e.g. "admin" in "admin.users"), or the connection's
     * active schema. Null when it cannot be determined (e.g. SQLite).
     *
     * @param  list<string>  $targets
     */
    private function relevantSchema(Connection $conn, array $targets): ?string
    {
        $explicit = $this->stringOption('schema');

        if ($explicit !== null) {
            return $explicit;
        }

        foreach ($targets as $target) {
            if (str_contains($target, '.')) {
                return substr($target, 0, strrpos($target, '.'));
            }
        }

        $driver = $conn->getDriverName();

        try {
            if ($driver === 'pgsql') {
                $searchPath = $conn->getConfig('search_path');

                if (is_array($searchPath) && $searchPath !== []) {
                    return (string) $searchPath[0];
                }

                if (is_string($searchPath) && trim($searchPath) !== '') {
                    return trim(explode(',', $searchPath)[0]);
                }

                return (string) $conn->scalar('select current_schema()');
            }

            if ($driver === 'sqlsrv') {
                return (string) $conn->scalar('select schema_name()');
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Count rows in a related table that reference the target through the given
     * FK column(s) — i.e. rows where every FK column is not null. Tries the
     * schema-qualified name first, then the bare name. Returns null if neither
     * can be counted.
     *
     * @param  array<string, string>  $qualifiedTables
     * @param  list<string>  $columns  the FK column(s) on the related table
     */
    private function countRows(Connection $conn, array $qualifiedTables, string $bare, array $columns): ?int
    {
        $candidates = array_values(array_unique(array_filter([
            $qualifiedTables[$bare] ?? null,
            $bare,
        ])));

        foreach ($candidates as $name) {
            try {
                $query = $conn->table($name);

                foreach ($columns as $column) {
                    $query->whereNotNull($column);
                }

                return (int) $query->count();
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $tables
     */
    private function printPlan(string $connection, ?string $schemaName, array $tables, StrategyManager $strategies): void
    {
        $this->line('  <options=bold>Plan</>');
        $this->line("    connection: <info>{$connection}</>".($schemaName ? "  schema: {$schemaName}" : ''));

        if ($this->option('data-only')) {
            $this->line('    mode: <info>data-only</> <fg=gray>(delete rows, keep schema)</>');
            $this->line('    clear order:   '.$this->planSummary($tables, true));
        } else {
            $this->line('    drop order:    '.$this->planSummary($tables, true));
            $this->line('    recreate order: '.$this->planSummary($tables, false));

            // Per-table strategy detail is only useful for a small set; a large
            // cascade would just dump every name (which the impact report shows).
            if (count($tables) <= self::PLAN_DETAIL_LIMIT) {
                foreach ($tables as $table) {
                    $name = $strategies->strategyNameFor($table, $this->stringOption('strategy'));
                    $detail = "      {$table}: strategy <info>{$name}</>";

                    if ($name === 'migration') {
                        $files = $this->laravel->make(TableResolver::class)->resolveMigrations($table);
                        $detail .= $files === []
                            ? ' <fg=red>(no migration resolved)</>'
                            : ' → '.implode(', ', array_map('basename', $files));
                    }

                    $this->line($detail);
                }
            }
        }

        if ($this->option('seed')) {
            $seeder = $this->stringOption('seeder') ?? 'default DatabaseSeeder';
            $this->line("    seed: <info>{$seeder}</>");
        }

        $this->newLine();
    }

    /**
     * Summarise an ordered table list for the plan: the names when the set is
     * small, otherwise just a count (so a large cascade does not dump 80 names).
     *
     * @param  list<string>  $tables
     */
    private function planSummary(array $tables, bool $childrenFirst): string
    {
        $count = count($tables);

        if ($count <= self::PLAN_DETAIL_LIMIT) {
            return implode(', ', $childrenFirst ? array_reverse($tables) : $tables);
        }

        return $count.' tables ('.($childrenFirst ? 'children-first' : 'parents-first').')';
    }

    private function maybeSeed(string $connection): void
    {
        if (! $this->option('seed')) {
            return;
        }

        $this->components->info('Seeding');

        $this->call('db:seed', array_filter([
            '--database' => $connection,
            '--class' => $this->stringOption('seeder'),
            '--force' => true,
        ], static fn ($value) => $value !== null));
    }

    /**
     * Decide which tables to fresh and whether to proceed. Returns the ordered
     * list to fresh (the targets, optionally expanded with their dependents),
     * or null if the run was aborted.
     *
     * @param  list<string>  $tables
     * @param  list<string>  $planned  the target set already expanded if --with-related
     * @return list<string>|null
     */
    private function decideFreshSet(Connection $conn, array $tables, array $planned): ?array
    {
        $withRelated = (bool) $this->option('with-related');

        // --force / --pretend never prompt; honour --with-related directly.
        if ($this->option('force') || $this->option('pretend')) {
            return $withRelated ? $planned : $tables;
        }

        if (! $this->input->isInteractive()) {
            $this->components->warn('Non-interactive mode requires --force to proceed.');

            return null;
        }

        // Explicit --with-related is itself the decision.
        if ($withRelated) {
            return $planned;
        }

        // Interactive: when dependents exist, offer to include them instead of a
        // plain yes/no. Detect dependents cheaply (one level) so the menu shows
        // immediately — the full recursive closure is only built if opted into.
        if (! $this->hasDependents($conn, $tables)) {
            return $this->confirm('The above tables are affected by foreign keys. Proceed?', false)
                ? $tables
                : null;
        }

        $list = implode(', ', $tables);
        $only = 'Fresh only '.$list;
        $with = 'Fresh '.$list.' and all tables that reference it';
        $cancel = 'Cancel';

        $choice = $this->choice(
            'The above tables are affected by foreign keys. What would you like to do?',
            [$only, $with, $cancel],
            $cancel,
        );

        if ($choice === $cancel) {
            return null;
        }

        if ($choice === $only) {
            return $tables;
        }

        $this->components->info('Resolving related tables…');

        return $this->relatedClosure($conn, $tables);
    }

    /**
     * Whether any table (outside the target set) references a target table.
     * One metadata pass — used to decide whether to offer the cascade choice.
     *
     * @param  list<string>  $tables
     */
    private function hasDependents(Connection $conn, array $tables): bool
    {
        $inspector = new ForeignKeyInspector($conn->getSchemaBuilder());
        $bare = array_map(fn (string $t): string => $this->bareName($t), $tables);

        foreach ($tables as $target) {
            foreach ($inspector->children($target) as $relation) {
                if (! in_array($relation->localTable, $bare, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The target tables plus every table that (transitively) references them,
     * ordered parents-first so the recreate step runs in dependency order.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function relatedClosure(Connection $conn, array $tables): array
    {
        $inspector = new ForeignKeyInspector($conn->getSchemaBuilder());
        $qualified = $this->qualifiedTableMap($conn, $tables);

        // Build the reverse adjacency (parent bare => dependent bares) once,
        // from a single metadata pass over every foreign key.
        /** @var array<string, array<string, true>> $childrenOf */
        $childrenOf = [];

        foreach ($inspector->allRelations() as $relation) {
            $parent = $relation->foreignTable;
            $child = $relation->localTable;

            if ($parent === $child) {
                continue; // self-reference
            }

            $childrenOf[$parent][$child] = true;
        }

        /** @var array<string, string> $nameOf bare name => display (qualified) name */
        $nameOf = [];

        foreach ($tables as $table) {
            $nameOf[$this->bareName($table)] = $table;
        }

        // Explore the dependents reachable from the targets.
        $explored = [];
        $stack = array_map(fn (string $t): string => $this->bareName($t), $tables);

        while ($stack !== []) {
            $bare = array_pop($stack);

            if (isset($explored[$bare])) {
                continue;
            }

            $explored[$bare] = true;
            $nameOf[$bare] ??= $qualified[$bare] ?? $bare;

            foreach (array_keys($childrenOf[$bare] ?? []) as $child) {
                if (! isset($explored[$child])) {
                    $stack[] = $child;
                }
            }
        }

        // Depth-first post-order yields children before parents; reverse it so
        // parents come first. The in-progress guard tolerates FK cycles.
        $order = [];
        $state = [];

        $visit = function (string $bare) use (&$visit, &$childrenOf, &$explored, &$order, &$state): void {
            if (isset($state[$bare])) {
                return;
            }

            $state[$bare] = true;

            foreach (array_keys($childrenOf[$bare] ?? []) as $child) {
                if (isset($explored[$child])) {
                    $visit($child);
                }
            }

            $order[] = $bare;
        };

        foreach (array_keys($explored) as $bare) {
            $visit($bare);
        }

        $order = array_reverse($order);

        return array_values(array_map(static fn (string $bare): string => $nameOf[$bare] ?? $bare, $order));
    }

    /**
     * @param  list<string>  $original
     * @param  list<string>  $full
     */
    private function printRelatedExpansion(
        string $connection,
        ?string $schemaName,
        array $original,
        array $full,
        StrategyManager $strategies,
    ): void {
        $related = array_values(array_filter($full, static fn (string $t): bool => ! in_array($t, $original, true)));

        $this->newLine();
        $this->components->info(sprintf('Including %d related table(s).', count($related)));

        $this->printPlan($connection, $schemaName, $full, $strategies);
    }

    private function bareName(string $name): string
    {
        return str_contains($name, '.')
            ? substr($name, strrpos($name, '.') + 1)
            : $name;
    }

    /**
     * Guard the run when APP_ENV is one of the configured protected
     * environments. Returns false when the command should abort.
     */
    private function confirmProtectedEnvironment(): bool
    {
        if (! $this->isProtectedEnvironment()) {
            return true;
        }

        // Read-only modes never mutate, so they are always allowed.
        if ($this->option('dry-run') || $this->option('pretend')) {
            return true;
        }

        // --force is an explicit, scripted "yes".
        if ($this->option('force')) {
            return true;
        }

        $environment = $this->laravel->environment();

        if (! $this->input->isInteractive()) {
            $this->components->error("Refusing to run in [{$environment}] without --force.");

            return false;
        }

        $this->components->warn("Application is in the [{$environment}] environment.");

        if ($this->confirm('Do you really wish to run this command?', false)) {
            return true;
        }

        $this->components->info('Command cancelled.');

        return false;
    }

    /**
     * Whether the current environment requires confirmation before freshing.
     */
    private function isProtectedEnvironment(): bool
    {
        /** @var array<int, string> $protected */
        $protected = (array) $this->laravel->make('config')
            ->get('migrate-fresh-table.protected_environments', ['production']);

        if ($protected === []) {
            return false;
        }

        return $this->laravel->environment($protected);
    }

    /**
     * @return list<string>
     */
    private function resolveTables(): array
    {
        $tables = [];

        if (is_string($this->argument('table')) && $this->argument('table') !== '') {
            $tables[] = trim((string) $this->argument('table'));
        }

        $list = $this->stringOption('tables');

        if ($list !== null) {
            foreach (explode(',', $list) as $table) {
                $table = trim($table);

                if ($table !== '') {
                    $tables[] = $table;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return list<string>
     */
    private function resolveConnections(): array
    {
        $explicit = $this->stringOption('connection') ?? $this->stringOption('database');

        if ($explicit !== null) {
            return [$explicit];
        }

        if ($this->option('all-connections')) {
            return $this->allConfiguredConnections();
        }

        return [(string) $this->laravel->make('config')->get('database.default')];
    }

    /**
     * @return list<string>
     */
    private function allConfiguredConnections(): array
    {
        $config = $this->laravel->make('config');
        $connections = (array) $config->get('migrate-fresh-table.connections', []);

        $resolver = $config->get('migrate-fresh-table.tenant_resolver');

        if (is_callable($resolver)) {
            foreach ((array) $resolver() as $name) {
                $connections[] = (string) $name;
            }
        }

        $connections = array_values(array_unique(array_filter(array_map('strval', $connections))));

        if ($connections === []) {
            $this->components->warn('--all-connections was passed but no connections/tenant_resolver are configured.');
        }

        return $connections;
    }

    /**
     * Apply a PostgreSQL search_path for the run, returning a restore callback.
     */
    private function applySchema(Connection $conn, ?string $schema): ?callable
    {
        if ($schema === null || $conn->getDriverName() !== 'pgsql') {
            return null;
        }

        $previous = $conn->getConfig('search_path') ?? 'public';
        $previous = is_array($previous) ? implode(',', $previous) : (string) $previous;

        $conn->statement('SET search_path TO '.$schema);

        return function () use ($conn, $previous): void {
            $conn->statement('SET search_path TO '.$previous);
        };
    }

    /**
     * @param  list<string>  $tables
     */
    private function runHook(string $hook, string $connection, array $tables): void
    {
        $callback = $this->laravel->make('config')->get("migrate-fresh-table.hooks.{$hook}");

        if (is_callable($callback)) {
            $callback($connection, $tables);
        }
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
