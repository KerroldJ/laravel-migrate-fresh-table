<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Contracts;

/**
 * Resolves the migration file(s) responsible for building a given table.
 *
 * The default implementation parses Schema::create()/Schema::table() calls and
 * falls back to a manual override map. Bind your own implementation to this
 * interface to customise resolution.
 */
interface TableResolver
{
    /**
     * Return the absolute migration file paths that build the table, in the
     * order they should be re-run. An empty array means "could not resolve".
     *
     * @return list<string>
     */
    public function resolveMigrations(string $table): array;
}
