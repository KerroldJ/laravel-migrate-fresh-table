<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Kerroldj\MigrateFreshTable\Support\ForeignKeyInspector;
use Kerroldj\MigrateFreshTable\Support\ForeignKeyRelation;

beforeEach(function () {
    $this->migrateConnection('testing');
});

it('detects child tables that reference the target', function () {
    $inspector = new ForeignKeyInspector(Schema::connection('testing'));

    $children = $inspector->children('users');

    expect($children)->toHaveCount(1);
    expect($children[0]->localTable)->toBe('posts');
    expect($children[0]->foreignTable)->toBe('users');
    expect($children[0]->direction)->toBe(ForeignKeyRelation::REFERENCED_BY);
    expect($children[0]->describe())->toBe('posts.user_id -> users.id');
});

it('detects parent tables that the target references', function () {
    $inspector = new ForeignKeyInspector(Schema::connection('testing'));

    $parents = $inspector->parents('posts');

    expect($parents)->toHaveCount(1);
    expect($parents[0]->foreignTable)->toBe('users');
    expect($parents[0]->isParent())->toBeTrue();
    expect($parents[0]->describe())->toBe('posts.user_id -> users.id');
});

it('lists every foreign-key relationship in one pass', function () {
    $inspector = new ForeignKeyInspector(Schema::connection('testing'));

    $all = $inspector->allRelations();

    expect($all)->toHaveCount(1);
    expect($all[0]->localTable)->toBe('posts');
    expect($all[0]->foreignTable)->toBe('users');
    expect($all[0]->describe())->toBe('posts.user_id -> users.id');
});

it('returns no relationships for a table without foreign keys', function () {
    $inspector = new ForeignKeyInspector(Schema::connection('testing'));

    expect($inspector->parents('users'))->toBe([]);
});

it('resolves parents when the target is passed schema-qualified', function () {
    // Drivers disagree on the accepted name form: SQL Server needs
    // "schema.table", SQLite needs the bare name. The inspector must try both
    // so a qualified argument still resolves the table's own foreign keys.
    $inspector = new ForeignKeyInspector(Schema::connection('testing'));

    $parents = $inspector->parents('main.posts');

    expect($parents)->toHaveCount(1);
    expect($parents[0]->localTable)->toBe('posts');
    expect($parents[0]->foreignTable)->toBe('users');
    expect($parents[0]->direction)->toBe(ForeignKeyRelation::REFERENCES);
});

it('finds children when the target is passed schema-qualified', function () {
    // SQL Server callers pass schema-qualified names like "admin.users".
    // The referenced-by detection must still match the underlying "users"
    // table, otherwise the impact report misses the children and the drop
    // fails because their constraints were never detached.
    $inspector = new ForeignKeyInspector(Schema::connection('testing'));

    $children = $inspector->children('main.users');

    expect($children)->toHaveCount(1);
    expect($children[0]->localTable)->toBe('posts');
    expect($children[0]->foreignTable)->toBe('users');
    expect($children[0]->direction)->toBe(ForeignKeyRelation::REFERENCED_BY);
});
