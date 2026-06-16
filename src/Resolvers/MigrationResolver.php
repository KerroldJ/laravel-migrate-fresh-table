<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Resolvers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Kerroldj\MigrateFreshTable\Contracts\TableResolver;
use Kerroldj\MigrateFreshTable\Exceptions\FreshTableException;

/**
 * Default TableResolver.
 *
 * Resolves the owning migration(s) for a table by:
 *   1. Consulting the manual "overrides" map (authoritative when present).
 *   2. Otherwise parsing migration files for Schema::create('<table>', ...).
 *
 * Files are returned sorted by filename so migration timestamps drive run order.
 */
final class MigrationResolver implements TableResolver
{
    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return list<string>
     */
    public function resolveMigrations(string $table): array
    {
        $override = $this->fromOverrides($table);

        if ($override !== []) {
            return $override;
        }

        return $this->fromAutoDetection($table);
    }

    /**
     * @return list<string>
     */
    private function fromOverrides(string $table): array
    {
        $overrides = (array) $this->config->get('migrate-fresh-table.overrides', []);

        if (! isset($overrides[$table])) {
            return [];
        }

        $paths = [];

        foreach ((array) $overrides[$table] as $path) {
            $absolute = $this->absolutePath((string) $path);

            if (! $this->files->exists($absolute)) {
                throw FreshTableException::migrationFileMissing($absolute);
            }

            $paths[] = $absolute;
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function fromAutoDetection(string $table): array
    {
        $paths = (array) $this->config->get('migrate-fresh-table.migration_paths', []);

        // Match the bare table name, optionally preceded by a "schema." prefix,
        // so `users` resolves a migration that creates `admin.users` (SQL Server
        // schemas) and a qualified `admin.users` argument resolves too.
        $bare = $this->stripSchema($table);
        $pattern = '/Schema::create\(\s*[\'"](?:\w+\.)?'.preg_quote($bare, '/').'[\'"]/';

        $matches = [];

        foreach ($paths as $directory) {
            if (! $this->files->isDirectory((string) $directory)) {
                continue;
            }

            foreach ($this->files->glob(rtrim((string) $directory, '/').'/*.php') as $file) {
                $contents = $this->files->get($file);

                if (preg_match($pattern, $contents) === 1) {
                    $matches[$file] = $file;
                }
            }
        }

        $matches = array_values($matches);
        sort($matches);

        return $matches;
    }

    private function absolutePath(string $path): string
    {
        if ($this->isAbsolute($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * Drop any "schema." prefix so a qualified table name (e.g. "admin.users")
     * is matched on its bare name.
     */
    private function stripSchema(string $name): string
    {
        if (str_contains($name, '.')) {
            return substr($name, strrpos($name, '.') + 1);
        }

        return $name;
    }
}
