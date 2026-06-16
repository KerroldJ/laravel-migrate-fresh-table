<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Support;

use Illuminate\Database\Schema\Builder;

/**
 * Immutable value object describing a single table-fresh operation on a single
 * connection. Passed to strategies so they have everything needed to re-create
 * a table without reaching into global state.
 */
final class FreshContext
{
    /**
     * @param  array<string, mixed>  $options  Raw command options for advanced strategies.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $connection,
        public readonly ?string $schema,
        public readonly Builder $schemaBuilder,
        public readonly bool $pretend = false,
        public readonly array $options = [],
    ) {}
}
