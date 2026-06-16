<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->app['env'] = 'production';
    $this->migrateConnection('testing');

    DB::connection('testing')->table('users')->insert([
        'name' => 'Prod',
        'email' => 'prod@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('asks for confirmation in production and proceeds when accepted', function () {
    $this->artisan('migrate:fresh-table', ['table' => 'posts'])
        ->expectsOutputToContain('Application is in the [production] environment.')
        ->expectsConfirmation('Do you really wish to run this command?', 'yes')
        // posts is referenced by no table, so a plain FK confirmation follows.
        ->expectsConfirmation('The above tables are affected by foreign keys. Proceed?', 'yes')
        ->assertExitCode(0);
});

it('asks for confirmation in production and aborts when declined', function () {
    $this->artisan('migrate:fresh-table', ['table' => 'posts'])
        ->expectsConfirmation('Do you really wish to run this command?', 'no')
        ->expectsOutputToContain('Command cancelled.')
        ->assertExitCode(1);

    expect(DB::connection('testing')->table('users')->count())->toBe(1);
});

it('allows a dry run in production without confirmation', function () {
    $this->artisan('migrate:fresh-table', ['table' => 'posts', '--dry-run' => true])
        ->assertExitCode(0);
});

it('runs in production when --force is given', function () {
    $this->artisan('migrate:fresh-table', ['table' => 'posts', '--force' => true])
        ->assertExitCode(0);
});

it('never asks about the environment outside a protected environment', function () {
    $this->app['env'] = 'local';

    $this->artisan('migrate:fresh-table', ['table' => 'posts', '--force' => true])
        ->doesntExpectOutputToContain('Do you really wish to run this command?')
        ->assertExitCode(0);
});

it('honours a custom protected_environments list', function () {
    $this->app['env'] = 'staging';
    config()->set('migrate-fresh-table.protected_environments', ['production', 'staging']);

    $this->artisan('migrate:fresh-table', ['table' => 'posts'])
        ->expectsOutputToContain('Application is in the [staging] environment.')
        ->expectsConfirmation('Do you really wish to run this command?', 'no')
        ->assertExitCode(1);
});

it('does not guard an environment that is not in the protected list', function () {
    $this->app['env'] = 'staging';

    // Default list only protects "production", so staging proceeds straight to
    // the foreign-key decision with no environment confirmation.
    $this->artisan('migrate:fresh-table', ['table' => 'posts'])
        ->doesntExpectOutputToContain('Do you really wish to run this command?')
        ->expectsConfirmation('The above tables are affected by foreign keys. Proceed?', 'no')
        ->assertExitCode(0);
});
