<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Events;

use Kerroldj\MigrateFreshTable\Events\Concerns\DescribesTableEvent;

/** Fired immediately before a table is re-created by a strategy. */
final class TableRecreating
{
    use DescribesTableEvent;
}
