<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kerroldj\MigrateFreshTable\Tests\Database\Seeders\PostSeeder;

beforeEach(function () {
    $this->migrateConnection('testing');
});

function seedPost(string $connection = 'testing'): void
{
    $userId = DB::connection($connection)->table('users')->insertGetId([
        'name' => 'Alice',
        'email' => 'alice@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection($connection)->table('posts')->insert([
        'user_id' => $userId,
        'title' => 'Hello',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('freshes a single table and wipes its data', function () {
    seedPost();

    expect(DB::connection('testing')->table('posts')->count())->toBe(1);

    $this->artisan('migrate:fresh-table', ['table' => 'posts', '--force' => true])
        ->assertExitCode(0);

    expect(Schema::connection('testing')->hasTable('posts'))->toBeTrue();
    expect(DB::connection('testing')->table('posts')->count())->toBe(0);
});

it('reports foreign-key relationships before acting', function () {
    seedPost();

    $this->artisan('migrate:fresh-table', ['table' => 'users', '--force' => true])
        ->expectsOutputToContain('Foreign-key impact report')
        ->expectsOutputToContain('posts.user_id -> users.id')
        ->assertExitCode(0);
});

it('renders the impact report as a table with a row-count column', function () {
    $userId = DB::connection('testing')->table('users')->insertGetId([
        'name' => 'Alice',
        'email' => 'alice@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (range(1, 3) as $i) {
        DB::connection('testing')->table('posts')->insert([
            'user_id' => $userId,
            'title' => "Post {$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->artisan('migrate:fresh-table', ['table' => 'users', '--dry-run' => true])
        ->expectsOutputToContain('Foreign-key impact report')
        ->expectsTable(
            ['Related table', 'Relationship', 'Rows'],
            [
                ['posts', 'posts.user_id -> users.id', '3'],
            ],
        )
        ->assertExitCode(0);
});

it('counts only dependent rows that reference the parent (FK not null)', function () {
    // A dependent with a NULLABLE FK: some rows point at the user, some don't.
    Schema::connection('testing')->create('audits', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained();
    });

    $userId = DB::connection('testing')->table('users')->insertGetId([
        'name' => 'Alice',
        'email' => 'alice@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('testing')->table('audits')->insert([
        ['user_id' => $userId],
        ['user_id' => $userId],
        ['user_id' => null],
        ['user_id' => null],
        ['user_id' => null],
    ]);

    // Rows must be 2 (referencing rows), not 5 (all rows).
    $this->artisan('migrate:fresh-table', ['table' => 'users', '--dry-run' => true])
        ->expectsTable(
            ['Related table', 'Relationship', 'Rows'],
            [
                ['audits', 'audits.user_id -> users.id', '2'],
                ['posts', 'posts.user_id -> users.id', '0'],
            ],
        )
        ->assertExitCode(0);
});

it('excludes parent references from the impact table and notes them separately', function () {
    // "posts" references a parent (users) and is referenced by a child
    // (comments). A fresh does not modify the parent, so only the dependent
    // "comments" belongs in the impact table; "users" is shown as a note.
    Schema::connection('testing')->create('comments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('post_id')->constrained()->cascadeOnDelete();
    });

    $this->artisan('migrate:fresh-table', ['table' => 'posts', '--dry-run' => true])
        ->expectsOutputToContain('references (re-created, not modified): users')
        ->expectsTable(
            ['Related table', 'Relationship', 'Rows'],
            [
                ['comments', 'comments.post_id -> posts.id', '0'],
            ],
        )
        ->assertExitCode(0);
});

it('freshes a table whose migration also creates sibling tables', function () {
    DB::connection('testing')->table('bundle_two')->insert(['label' => 'keep?']);

    // bundle_one and bundle_two are built by one migration; recreating bundle_one
    // re-runs that file, which must not collide with the still-present sibling.
    $this->artisan('migrate:fresh-table', ['table' => 'bundle_one', '--force' => true])
        ->assertExitCode(0);

    expect(Schema::connection('testing')->hasTable('bundle_one'))->toBeTrue();
    expect(Schema::connection('testing')->hasTable('bundle_two'))->toBeTrue();
    // The bundled sibling is rebuilt too, so its data is wiped.
    expect(DB::connection('testing')->table('bundle_two')->count())->toBe(0);
});

it('clears data without dropping tables when --data-only is passed', function () {
    seedPost();

    expect(DB::connection('testing')->table('users')->count())->toBe(1);
    expect(DB::connection('testing')->table('posts')->count())->toBe(1);

    $this->artisan('migrate:fresh-table', [
        'table' => 'users',
        '--with-related' => true,
        '--data-only' => true,
        '--force' => true,
    ])->assertExitCode(0);

    // Tables are untouched (never dropped), only their rows are cleared.
    expect(Schema::connection('testing')->hasTable('users'))->toBeTrue();
    expect(Schema::connection('testing')->hasTable('posts'))->toBeTrue();
    expect(DB::connection('testing')->table('users')->count())->toBe(0);
    expect(DB::connection('testing')->table('posts')->count())->toBe(0);
});

it('rolls back every drop when a table cannot be recreated', function () {
    seedPost();

    // A dependent with data but NO resolvable migration: recreating it fails.
    Schema::connection('testing')->create('audits', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
    });
    DB::connection('testing')->table('audits')->insert([
        'user_id' => DB::connection('testing')->table('users')->value('id'),
    ]);

    $this->artisan('migrate:fresh-table', ['--tables' => 'users,audits', '--force' => true])
        ->assertExitCode(1);

    // The failed run must leave everything intact — no partial drop/wipe.
    expect(Schema::connection('testing')->hasTable('users'))->toBeTrue();
    expect(Schema::connection('testing')->hasTable('audits'))->toBeTrue();
    expect(DB::connection('testing')->table('users')->count())->toBe(1);
    expect(DB::connection('testing')->table('audits')->count())->toBe(1);
});

it('also freshes dependent tables when --with-related is passed', function () {
    seedPost();

    expect(DB::connection('testing')->table('posts')->count())->toBe(1);

    $this->artisan('migrate:fresh-table', [
        'table' => 'users',
        '--with-related' => true,
        '--force' => true,
    ])->assertExitCode(0);

    expect(Schema::connection('testing')->hasTable('users'))->toBeTrue();
    expect(Schema::connection('testing')->hasTable('posts'))->toBeTrue();
    expect(DB::connection('testing')->table('users')->count())->toBe(0);
    expect(DB::connection('testing')->table('posts')->count())->toBe(0);
});

it('offers to fresh related tables instead of a yes/no prompt', function () {
    seedPost();

    $this->artisan('migrate:fresh-table', ['table' => 'users'])
        ->expectsChoice(
            'The above tables are affected by foreign keys. What would you like to do?',
            'Fresh users and all tables that reference it',
            ['Fresh only users', 'Fresh users and all tables that reference it', 'Cancel'],
        )
        ->assertExitCode(0);

    expect(DB::connection('testing')->table('posts')->count())->toBe(0);
});

it('aborts cleanly when the confirmation is declined', function () {
    seedPost();

    $this->artisan('migrate:fresh-table', ['table' => 'posts'])
        ->expectsConfirmation('The above tables are affected by foreign keys. Proceed?', 'no')
        ->expectsOutputToContain('Aborted by user')
        ->assertExitCode(0);

    expect(DB::connection('testing')->table('posts')->count())->toBe(1);
});

it('proceeds when the confirmation is accepted', function () {
    seedPost();

    $this->artisan('migrate:fresh-table', ['table' => 'posts'])
        ->expectsConfirmation('The above tables are affected by foreign keys. Proceed?', 'yes')
        ->assertExitCode(0);

    expect(DB::connection('testing')->table('posts')->count())->toBe(0);
});

it('does not execute in dry-run mode', function () {
    seedPost();

    $this->artisan('migrate:fresh-table', ['table' => 'posts', '--dry-run' => true])
        ->expectsOutputToContain('Plan')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(DB::connection('testing')->table('posts')->count())->toBe(1);
});

it('prints SQL without executing in pretend mode', function () {
    seedPost();

    $this->artisan('migrate:fresh-table', ['table' => 'posts', '--pretend' => true])
        ->expectsOutputToContain('Pretend')
        ->assertExitCode(0);

    // Pretend never drops the table.
    expect(DB::connection('testing')->table('posts')->count())->toBe(1);
});

it('freshes multiple ordered tables in one call', function () {
    seedPost();

    $this->artisan('migrate:fresh-table', ['--tables' => 'users,posts', '--force' => true])
        ->assertExitCode(0);

    expect(DB::connection('testing')->table('users')->count())->toBe(0);
    expect(DB::connection('testing')->table('posts')->count())->toBe(0);
});

it('fails when no table is specified', function () {
    $this->artisan('migrate:fresh-table', ['--force' => true])
        ->expectsOutputToContain('No table specified')
        ->assertExitCode(1);
});

it('re-seeds after recreating when --seed is passed', function () {
    seedPost();

    $this->artisan('migrate:fresh-table', [
        'table' => 'posts',
        '--force' => true,
        '--seed' => true,
        '--seeder' => PostSeeder::class,
    ])->assertExitCode(0);

    expect(DB::connection('testing')->table('posts')->count())->toBe(1);
});
