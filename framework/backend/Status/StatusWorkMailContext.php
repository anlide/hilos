<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Private to one owner's pending mail tasks for one public work identity; never replicated or persisted. */
final class StatusWorkMailContext
{
    public int $pendingTasks = 0;
    public ?int $workVersion = null;
    public ?string $origin = null;
    public ?string $originRequestId = null;
}
