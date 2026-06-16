<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Events;

use Kerroldj\MigrateFreshTable\Events\Concerns\DescribesTableEvent;

/** Fired immediately after a table is dropped. */
final class TableDropped
{
    use DescribesTableEvent;
}
