<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Events;

use Kerroldj\MigrateFreshTable\Events\Concerns\DescribesTableEvent;

/** Fired immediately before a table is dropped. */
final class TableDropping
{
    use DescribesTableEvent;
}
