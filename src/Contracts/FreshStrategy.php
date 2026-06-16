<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Contracts;

use Kerroldj\MigrateFreshTable\Support\FreshContext;

/**
 * A strategy that knows how to re-create a single table after it has been
 * dropped. Bind a custom implementation under config('migrate-fresh-table.
 * strategies') to plug in your own behaviour.
 */
interface FreshStrategy
{
    /**
     * The canonical name of the strategy (matches the registry key).
     */
    public function name(): string;

    /**
     * Re-create the table described by the given context.
     *
     * Implementations must NOT drop the table — dropping is orchestrated by
     * the command (with foreign-key checks disabled). This method is only
     * responsible for (re)building the table structure.
     */
    public function recreate(FreshContext $context): void;
}
