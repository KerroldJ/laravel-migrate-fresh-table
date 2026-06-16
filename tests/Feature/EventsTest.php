<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Kerroldj\MigrateFreshTable\Events\TableDropped;
use Kerroldj\MigrateFreshTable\Events\TableDropping;
use Kerroldj\MigrateFreshTable\Events\TableFreshed;
use Kerroldj\MigrateFreshTable\Events\TableFreshing;
use Kerroldj\MigrateFreshTable\Events\TableRecreated;
use Kerroldj\MigrateFreshTable\Events\TableRecreating;

beforeEach(function () {
    $this->migrateConnection('testing');
});

it('fires the table lifecycle events', function () {
    Event::fake([
        TableFreshing::class,
        TableDropping::class,
        TableDropped::class,
        TableRecreating::class,
        TableRecreated::class,
        TableFreshed::class,
    ]);

    $this->artisan('migrate:fresh-table', ['table' => 'posts', '--force' => true])
        ->assertExitCode(0);

    Event::assertDispatched(TableFreshing::class, fn ($e) => $e->table === 'posts');
    Event::assertDispatched(TableDropping::class, fn ($e) => $e->table === 'posts');
    Event::assertDispatched(TableDropped::class, fn ($e) => $e->table === 'posts');
    Event::assertDispatched(TableRecreating::class, fn ($e) => $e->table === 'posts');
    Event::assertDispatched(TableRecreated::class, fn ($e) => $e->table === 'posts');
    Event::assertDispatched(TableFreshed::class, fn ($e) => $e->connection === 'testing');
});

it('invokes the global before/after hooks', function () {
    $log = [];

    config()->set('migrate-fresh-table.hooks.before', function (string $connection, array $tables) use (&$log) {
        $log[] = "before:{$connection}:".implode(',', $tables);
    });
    config()->set('migrate-fresh-table.hooks.after', function (string $connection, array $tables) use (&$log) {
        $log[] = "after:{$connection}:".implode(',', $tables);
    });

    $this->artisan('migrate:fresh-table', ['table' => 'posts', '--force' => true])
        ->assertExitCode(0);

    expect($log)->toBe(['before:testing:posts', 'after:testing:posts']);
});
