<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('operates on an explicitly chosen connection', function () {
    $this->migrateConnection('secondary');

    DB::connection('secondary')->table('users')->insert([
        'name' => 'Bob',
        'email' => 'bob@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('migrate:fresh-table', [
        'table' => 'users',
        '--connection' => 'secondary',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Schema::connection('secondary')->hasTable('users'))->toBeTrue();
    expect(DB::connection('secondary')->table('users')->count())->toBe(0);
});

it('iterates every configured connection with --all-connections', function () {
    config()->set('migrate-fresh-table.connections', ['testing', 'secondary']);

    $this->migrateConnection('testing');
    $this->migrateConnection('secondary');

    foreach (['testing', 'secondary'] as $connection) {
        DB::connection($connection)->table('users')->insert([
            'name' => 'User',
            'email' => "user@{$connection}.test",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->artisan('migrate:fresh-table', [
        'table' => 'users',
        '--all-connections' => true,
        '--force' => true,
    ])->assertExitCode(0);

    expect(DB::connection('testing')->table('users')->count())->toBe(0);
    expect(DB::connection('secondary')->table('users')->count())->toBe(0);
});

it('resolves tenant connections from the tenant_resolver callback', function () {
    config()->set('migrate-fresh-table.connections', []);
    config()->set('migrate-fresh-table.tenant_resolver', fn () => ['secondary']);

    $this->migrateConnection('secondary');

    DB::connection('secondary')->table('users')->insert([
        'name' => 'Tenant',
        'email' => 'tenant@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('migrate:fresh-table', [
        'table' => 'users',
        '--all-connections' => true,
        '--force' => true,
    ])->assertExitCode(0);

    expect(DB::connection('secondary')->table('users')->count())->toBe(0);
});
