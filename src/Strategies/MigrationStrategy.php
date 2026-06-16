<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Strategies;

use Kerroldj\MigrateFreshTable\Contracts\FreshStrategy;
use Kerroldj\MigrateFreshTable\Contracts\TableResolver;
use Kerroldj\MigrateFreshTable\Exceptions\FreshTableException;
use Kerroldj\MigrateFreshTable\Support\FreshContext;
use ReflectionProperty;

/**
 * Re-creates a table by re-running the migration(s) that build it.
 *
 * The command drops the table first (with FK checks disabled) and sets the
 * resolved connection as the default for the duration, so the Schema calls
 * inside the migration's up() target the correct connection/schema.
 */
final class MigrationStrategy implements FreshStrategy
{
    public function __construct(private readonly TableResolver $resolver) {}

    public function name(): string
    {
        return 'migration';
    }

    public function recreate(FreshContext $context): void
    {
        $files = $this->resolver->resolveMigrations($context->table);

        if ($files === []) {
            throw FreshTableException::unresolvedMigration($context->table);
        }

        foreach ($files as $file) {
            $this->runMigration($file, $context);
        }
    }

    private function runMigration(string $file, FreshContext $context): void
    {
        $migration = $this->instantiate($file);

        // Ensure the migration runs against the resolved connection. Modern
        // migrations read $this->connection; setting it keeps Schema calls and
        // any DB statements on the right tenant database.
        if (property_exists($migration, 'connection')) {
            $this->forceConnection($migration, $context->connection);
        }

        if (method_exists($migration, 'up')) {
            $migration->up();
        }
    }

    private function instantiate(string $file): object
    {
        $migration = require $file;

        if (is_object($migration)) {
            return $migration;
        }

        // Legacy class-name migrations: derive the class from the filename.
        $class = $this->classFromFile($file);

        if ($class !== null && class_exists($class)) {
            return new $class;
        }

        throw FreshTableException::migrationFileMissing($file);
    }

    private function classFromFile(string $file): ?string
    {
        $name = basename($file, '.php');
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name) ?? $name;

        if ($name === '') {
            return null;
        }

        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    private function forceConnection(object $migration, string $connection): void
    {
        $property = new ReflectionProperty($migration, 'connection');
        $property->setValue($migration, $connection);
    }
}
