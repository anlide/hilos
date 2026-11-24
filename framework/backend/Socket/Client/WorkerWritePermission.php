<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Closure;

/** Admission attached to an exact unstarted line in the worker link's existing byte buffer. */
final readonly class WorkerWritePermission
{
    /**
     * @param int $offset Logical byte at which the line starts
     * @param int $length Line's byte length including newline
     * @param Closure(): bool $permitted Current authority to start the line
     */
    public function __construct(public int $offset, public int $length, public Closure $permitted)
    {
    }
}
