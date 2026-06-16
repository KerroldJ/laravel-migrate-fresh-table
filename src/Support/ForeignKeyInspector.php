<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Support;

use Illuminate\Database\Schema\Builder;
use Throwable;

/**
 * Inspects the live schema (via Laravel's driver-agnostic schema introspection)
 * to discover every foreign-key relationship touching a table — both the
 * parents it references and the children that reference it.
 *
 * Works across MySQL/MariaDB, PostgreSQL, SQLite and SQL Server because it
 * relies on Builder::getForeignKeys()/getTables(), which each driver's grammar
 * implements against the appropriate metadata source (information_schema,
 * pragma_foreign_key_list, sys.foreign_keys, etc.).
 *
 * Drivers disagree on the table-name form getForeignKeys() accepts: SQL Server
 * needs the schema-qualified name ("admin.users"), while SQLite only resolves
 * the bare name. Every lookup therefore tries the qualified form first and
 * falls back to the bare name, so a table in a non-default schema is found.
 */
final class ForeignKeyInspector
{
    public function __construct(private readonly Builder $schema) {}

    /**
     * Foreign keys defined ON $table pointing at other tables (parents).
     *
     * @return list<ForeignKeyRelation>
     */
    public function parents(string $table): array
    {
        $target = $this->stripSchema($table);
        $relations = [];

        foreach ($this->foreignKeysOfTable($table) as $fk) {
            $relations[] = new ForeignKeyRelation(
                constraint: (string) ($fk['name'] ?? ''),
                localTable: $target,
                localColumns: $this->columns($fk['columns'] ?? []),
                foreignTable: $this->stripSchema((string) ($fk['foreign_table'] ?? '')),
                foreignColumns: $this->columns($fk['foreign_columns'] ?? []),
                direction: ForeignKeyRelation::REFERENCES,
                onDelete: $this->action($fk['on_delete'] ?? null),
                onUpdate: $this->action($fk['on_update'] ?? null),
            );
        }

        return $relations;
    }

    /**
     * Foreign keys on OTHER tables pointing at $table (children/dependents).
     *
     * @return list<ForeignKeyRelation>
     */
    public function children(string $table): array
    {
        // The caller may pass a schema-qualified name (e.g. "admin.users").
        // Driver metadata reports names with their own qualification, so the
        // comparison is always done on the bare table name from both sides.
        $target = $this->stripSchema($table);

        $relations = [];

        foreach ($this->tableDescriptors() as $descriptor) {
            if ($descriptor['bare'] === $target) {
                continue;
            }

            foreach ($this->foreignKeysFor($descriptor) as $fk) {
                if ($this->stripSchema((string) ($fk['foreign_table'] ?? '')) !== $target) {
                    continue;
                }

                $relations[] = new ForeignKeyRelation(
                    constraint: (string) ($fk['name'] ?? ''),
                    localTable: $descriptor['bare'],
                    localColumns: $this->columns($fk['columns'] ?? []),
                    foreignTable: $target,
                    foreignColumns: $this->columns($fk['foreign_columns'] ?? []),
                    direction: ForeignKeyRelation::REFERENCED_BY,
                    onDelete: $this->action($fk['on_delete'] ?? null),
                    onUpdate: $this->action($fk['on_update'] ?? null),
                );
            }
        }

        return $relations;
    }

    /**
     * Every foreign-key relationship in the schema, discovered in a single
     * metadata pass (each as a child -> parent relation). Useful for building
     * the full dependency graph without re-scanning per table.
     *
     * @return list<ForeignKeyRelation>
     */
    public function allRelations(): array
    {
        $relations = [];

        foreach ($this->tableDescriptors() as $descriptor) {
            foreach ($this->foreignKeysFor($descriptor) as $fk) {
                $relations[] = new ForeignKeyRelation(
                    constraint: (string) ($fk['name'] ?? ''),
                    localTable: $descriptor['bare'],
                    localColumns: $this->columns($fk['columns'] ?? []),
                    foreignTable: $this->stripSchema((string) ($fk['foreign_table'] ?? '')),
                    foreignColumns: $this->columns($fk['foreign_columns'] ?? []),
                    direction: ForeignKeyRelation::REFERENCED_BY,
                    onDelete: $this->action($fk['on_delete'] ?? null),
                    onUpdate: $this->action($fk['on_update'] ?? null),
                );
            }
        }

        return $relations;
    }

    /**
     * Foreign keys for an arbitrary table name. Tries the name as given, then
     * (for a bare name in a non-default schema) the qualified form discovered
     * from the catalog.
     *
     * @return list<array<string, mixed>>
     */
    private function foreignKeysOfTable(string $table): array
    {
        $keys = $this->foreignKeysOf($table);

        if ($keys !== []) {
            return $keys;
        }

        $target = $this->stripSchema($table);

        foreach ($this->tableDescriptors() as $descriptor) {
            if ($descriptor['bare'] === $target) {
                return $this->foreignKeysFor($descriptor);
            }
        }

        return [];
    }

    /**
     * Foreign keys for a discovered table, trying each candidate name form
     * until one yields results.
     *
     * @param  array{bare: string, lookup: list<string>}  $descriptor
     * @return list<array<string, mixed>>
     */
    private function foreignKeysFor(array $descriptor): array
    {
        foreach ($descriptor['lookup'] as $name) {
            $keys = $this->foreignKeysOf($name);

            if ($keys !== []) {
                return $keys;
            }
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function foreignKeysOf(string $table): array
    {
        try {
            /** @var list<array<string, mixed>> $keys */
            $keys = $this->schema->getForeignKeys($table);

            return $keys;
        } catch (Throwable) {
            // Table may not exist yet, or the driver may not support FK
            // introspection (e.g. some SQLite builds). Treat as "no FKs".
            return [];
        }
    }

    /**
     * Every table in the schema as a descriptor: its bare name (for display and
     * comparison) plus the ordered name forms to try when querying foreign keys
     * (schema-qualified first, then bare).
     *
     * @return list<array{bare: string, lookup: list<string>}>
     */
    private function tableDescriptors(): array
    {
        $descriptors = [];

        foreach ($this->schema->getTables() as $table) {
            if (is_array($table)) {
                $bare = (string) ($table['name'] ?? '');
                $qualified = (string) ($table['schema_qualified_name'] ?? '');

                if ($bare === '') {
                    $bare = $this->stripSchema($qualified);
                }

                if ($bare === '') {
                    continue;
                }

                /** @var list<string> $lookup */
                $lookup = array_values(array_unique(array_filter([$qualified, $bare])));
            } elseif (is_string($table)) {
                $bare = $this->stripSchema($table);
                $lookup = [$table];
            } else {
                continue;
            }

            $descriptors[] = ['bare' => $bare, 'lookup' => $lookup];
        }

        return $descriptors;
    }

    /**
     * @return list<string>
     */
    private function columns(mixed $columns): array
    {
        return array_values(array_map('strval', (array) $columns));
    }

    /**
     * Normalise a referential action ("cascade", "set null", …). "no action"
     * and empty values are the implicit default, so they are returned as null.
     */
    private function action(mixed $action): ?string
    {
        if (! is_string($action) || $action === '') {
            return null;
        }

        $action = strtolower($action);

        return $action === 'no action' ? null : $action;
    }

    /**
     * Drop any "schema." prefix so comparisons are stable across drivers.
     */
    private function stripSchema(string $name): string
    {
        if (str_contains($name, '.')) {
            return substr($name, strrpos($name, '.') + 1);
        }

        return $name;
    }
}
