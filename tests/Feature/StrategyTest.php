<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('recreates a table from a Blueprint via the schema strategy', function () {
    Schema::connection('testing')->create('widgets', function ($table) {
        $table->id();
        $table->string('label');
        $table->timestamps();
    });

    DB::connection('testing')->table('widgets')->insert([
        'label' => 'first',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::connection('testing')->table('widgets')->count())->toBe(1);

    $this->artisan('migrate:fresh-table', [
        'table' => 'widgets',
        '--strategy' => 'schema',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Schema::connection('testing')->hasTable('widgets'))->toBeTrue();
    expect(DB::connection('testing')->table('widgets')->count())->toBe(0);
});

it('fails with an actionable message when a migration cannot be resolved', function () {
    $this->migrateConnection('testing');

    Schema::connection('testing')->create('orphans', function ($table) {
        $table->id();
    });

    $this->artisan('migrate:fresh-table', ['table' => 'orphans', '--force' => true])
        ->expectsOutputToContain('Could not resolve the migration for table [orphans]')
        ->assertExitCode(1);
});

it('uses the per-table strategy override from config', function () {
    config()->set('migrate-fresh-table.table_strategies', ['widgets' => 'schema']);

    Schema::connection('testing')->create('widgets', function ($table) {
        $table->id();
        $table->string('label');
        $table->timestamps();
    });

    $this->artisan('migrate:fresh-table', ['table' => 'widgets', '--force' => true])
        ->assertExitCode(0);

    expect(Schema::connection('testing')->hasTable('widgets'))->toBeTrue();
});
