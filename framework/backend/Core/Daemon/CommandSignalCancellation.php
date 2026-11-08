<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** One caller's finite local cancellation debt, containing no completed payload reference. */
final class CommandSignalCancellation
{
    public bool $holdsComplete = false;

    /**
     * @param int $id Owner-local cancellation identity
     * @param string $correlationId Caller that stopped waiting
     * @param int $inputTail Last already-queued ordinary admission covered by this cancellation
     */
    public function __construct(public readonly int $id, public readonly string $correlationId, public readonly int $inputTail)
    {
    }
}
