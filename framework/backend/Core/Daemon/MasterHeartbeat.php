<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ProcessException;
use Hilos\Core\Process;
use Throwable;

/** Local proof of completed master passes, carried separately from daemon output. */
final class MasterHeartbeat
{
    public const int DESCRIPTOR = 3;
    public const string ARGUMENT = '--watchdog-heartbeat';
    public const string MARK = "\x01";
    public const float INTERVAL_SECONDS = 10.0;
    public const float STALE_SECONDS = 60.0;
    public const float STOP_GRACE_SECONDS = 5.0;
    public const int READ_BYTES = 4096;

    private ?float $nextMarkAt;

    /**
     * @param resource $pipe Writer owned by this master, closed with it
     * @param float $now Initial monotonic deadline, due before the first wait
     * @throws ProcessException When the declared writer cannot be made nonblocking
     */
    public function __construct(private mixed $pipe, float $now)
    {
        if (!is_resource($pipe) || !stream_set_blocking($pipe, false)) {
            throw new ProcessException('Cannot make the master heartbeat writer nonblocking.');
        }
        $this->nextMarkAt = $now;
    }

    /**
     * Only the daemon entrypoint opts in; worker argv never carries this argument.
     *
     * @param list<string> $argv Current process arguments
     * @return ?self Writer when explicitly supervised, otherwise absent without opening fd 3
     * @throws ProcessException When the declared heartbeat descriptor cannot be opened or configured
     */
    public static function fromArguments(array $argv): ?self
    {
        if (!in_array(self::ARGUMENT, $argv, true)) {
            return null;
        }
        try {
            $pipe = fopen('php://fd/' . self::DESCRIPTOR, Process::PIPE_WRITE);
            try {
                return new self($pipe, hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND);
            } catch (Throwable $failure) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
                throw $failure;
            }
        } catch (Throwable $failure) {
            throw new ProcessException('Cannot initialize the master heartbeat writer.', previous: $failure);
        }
    }

    /**
     * One attempt per own interval; a full pipe retains no queue or catch-up debt.
     *
     * @param float $now Monotonic time after a successful guarded pass
     * @throws ProcessException When the declared writer refuses a mark
     */
    public function onPassCompleted(float $now): void
    {
        if ($this->nextMarkAt === null || $now < $this->nextMarkAt) {
            return;
        }
        if (!is_resource($this->pipe)) {
            throw new ProcessException('The master heartbeat writer is no longer open.');
        }
        try {
            $written = fwrite($this->pipe, self::MARK);
        } catch (Throwable $failure) {
            throw new ProcessException('Cannot write the master heartbeat mark.', previous: $failure);
        }
        if ($written === false) {
            throw new ProcessException('Cannot write the master heartbeat mark.');
        }
        $this->nextMarkAt = $now + self::INTERVAL_SECONDS;
    }

    /** @return ?float Next attempt's monotonic deadline, absent after close */
    public function nextMarkAt(): ?float
    {
        return $this->nextMarkAt;
    }

    /** Releases only this master's writer; safe to repeat. */
    public function close(): void
    {
        $this->nextMarkAt = null;
        if (is_resource($this->pipe)) {
            fclose($this->pipe);
        }
        $this->pipe = null;
    }
}
