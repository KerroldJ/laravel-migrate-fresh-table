<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Exceptions;

use Kerroldj\MigrateFreshTable\Contracts\FreshStrategy;
use RuntimeException;

class FreshTableException extends RuntimeException
{
    public static function unresolvedMigration(string $table): self
    {
        return new self(
            "Could not resolve the migration for table [{$table}]. ".
            "Define it under config('migrate-fresh-table.overrides') or switch ".
            "that table to the 'schema' strategy."
        );
    }

    public static function missingSchemaDefinition(string $table): self
    {
        return new self(
            "No schema definition found for table [{$table}]. ".
            "Add a Blueprint callback under config('migrate-fresh-table.schema.{$table}')."
        );
    }

    public static function unknownStrategy(string $name): self
    {
        return new self(
            "Unknown fresh strategy [{$name}]. ".
            "Register it under config('migrate-fresh-table.strategies')."
        );
    }

    public static function invalidStrategy(string $class): self
    {
        return new self(
            "Strategy [{$class}] must implement ".
            FreshStrategy::class.'.'
        );
    }

    public static function migrationFileMissing(string $path): self
    {
        return new self("Migration file [{$path}] does not exist.");
    }
}
