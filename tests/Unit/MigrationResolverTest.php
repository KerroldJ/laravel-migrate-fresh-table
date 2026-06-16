<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Kerroldj\MigrateFreshTable\Resolvers\MigrationResolver;

function schemaMigrationResolver(): MigrationResolver
{
    return new MigrationResolver(
        new Repository([
            'migrate-fresh-table' => [
                'migration_paths' => [__DIR__.'/../fixtures/schema-migrations'],
                'overrides' => [],
            ],
        ]),
        new Filesystem,
    );
}

it('auto-detects a schema-qualified migration when given the bare table name', function () {
    // The migration creates "admin.users"; the user types just "users".
    $files = schemaMigrationResolver()->resolveMigrations('users');

    expect($files)->toHaveCount(1);
    expect(basename($files[0]))->toBe('0001_01_01_000000_create_admin_users_table.php');
});

it('auto-detects a schema-qualified migration when given the qualified table name', function () {
    $files = schemaMigrationResolver()->resolveMigrations('admin.users');

    expect($files)->toHaveCount(1);
    expect(basename($files[0]))->toBe('0001_01_01_000000_create_admin_users_table.php');
});
