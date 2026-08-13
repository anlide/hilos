<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

use Hilos\Backup\BackupRestorer;
use Throwable;

/**
 * Thrown when a step of the backup restore path fails or refuses to run.
 *
 * Raised both by the preflight refusals (missing/ambiguous archive, failed digest
 * check, unavailable anonymizer) and by the destructive steps themselves (extract,
 * per-connection import). The message names the failing step and connection for the
 * operator.
 *
 * It also says whether the database was already being written to when the step failed
 * (HIL-436). That is the difference between "nothing was lost, fix the archive and try
 * again" and "this database may be half-overwritten", and it is the first thing the
 * person holding a failed restore needs to know. A plain construction means untouched:
 * {@see BackupRestorer} only raises the touched flavor from inside the destructive
 * window, so every refusal outside it is safe by construction rather than by tagging.
 */
class RestoreFailedException extends BackupException
{
    /** @var bool Whether the run had begun writing to the database when this was raised */
    private bool $databaseTouched = false;

    /**
     * A failure that left the database exactly as it was.
     *
     * @param string $message Operator-facing failure detail
     * @param ?Throwable $previous Underlying failure, when there is one
     * @return static Failure carrying "the database is intact"
     */
    public static function beforeDestructive(string $message, ?Throwable $previous = null): static
    {
        return new static($message, 0, $previous);
    }

    /**
     * A failure raised once the run had begun replacing the database.
     *
     * @param string $message Operator-facing failure detail
     * @param ?Throwable $previous Underlying failure, when there is one
     * @return static Failure carrying "the database may be partially restored"
     */
    public static function afterDestructive(string $message, ?Throwable $previous = null): static
    {
        $exception = new static($message, 0, $previous);
        $exception->databaseTouched = true;

        return $exception;
    }

    /**
     * @return bool True when the run had begun writing to the database before it failed
     */
    public function databaseTouched(): bool
    {
        return $this->databaseTouched;
    }
}
