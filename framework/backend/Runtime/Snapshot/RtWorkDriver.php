<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Generator;
use LogicException;
use Throwable;

/** Drives a suspended row operation, charging each request before resuming it. */
final class RtWorkDriver
{
    private bool $finished = false;
    private bool $canceled = false;
    private mixed $result = null;

    /**
     * @param ?Generator $operation Operation yielding RtWork before each bounded part
     */
    public function __construct(private ?Generator $operation)
    {
    }

    /**
     * @param RtWorkBudget $budget Shared allowance of this RT pass
     * @throws LogicException When the operation does not yield work requests
     * @throws Throwable Whatever the row's validation or preparation raises
     */
    public function advance(RtWorkBudget $budget): void
    {
        if ($this->finished || $this->canceled) {
            return;
        }
        try {
            while ($this->operation->valid()) {
                $request = $this->operation->current();
                if (!$request instanceof RtWork) {
                    throw new LogicException('A resumable RT operation must yield an RtWork request');
                }
                $bytes = $budget->take($request->bytes);
                if ($bytes === null) {
                    return;
                }
                $this->operation->send($bytes);
            }
            $this->result = $this->operation->getReturn();
            $this->finished = true;
            $this->operation = null;
        } catch (Throwable $exception) {
            $this->cancel();
            throw $exception;
        }
    }

    /** Closes the generation immediately; page leases retire through their arena. */
    public function cancel(): void
    {
        $this->canceled = true;
        $this->operation = null;
        $this->result = null;
    }

    /** @return bool Whether the whole operation completed */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /** @return bool Whether cancellation or refusal closed the operation */
    public function isCanceled(): bool
    {
        return $this->canceled;
    }

    /**
     * @return mixed Prepared value, available only after completion
     * @throws LogicException When preparation has not completed or was canceled
     */
    public function result(): mixed
    {
        if (!$this->finished || $this->canceled) {
            throw new LogicException('RT preparation has not completed');
        }

        return $this->result;
    }

    /**
     * Explicit synchronous compatibility door. Runtime replication must use advance().
     *
     * @param Generator $operation Segmented operation
     * @return mixed Completed value
     * @throws LogicException When the operation violates the work protocol
     * @throws Throwable Whatever validation or preparation raises
     */
    public static function runSynchronously(Generator $operation): mixed
    {
        $driver = new self($operation);
        while (!$driver->isFinished()) {
            $driver->advance(new RtWorkBudget());
        }

        return $driver->result();
    }
}
