<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupCeilingGuard - what spared one row from the ceiling pass.
 *
 * {@see BackupPruner::selectForCeiling()} reports the FIRST guard that held a row, and the cases
 * are declared from the least removable to the most: an error record carries no archive at all,
 * while an unshipped one is held by a channel that can be repaired. Read that way the label
 * 'awaiting shipment' means "nothing else holds this row" - fix the channel and it leaves. Any
 * other order would blur exactly the diagnosis the operator reads the log line for.
 */
enum BackupCeilingGuard: string
{
    /** A recorded failure: there is no archive behind it, so deleting it frees nothing. */
    case ERROR_RECORD = 'error_record';

    /** The newest successful backup of its scope: the store must not thin down to zero restore points. */
    case NEWEST_OF_SCOPE = 'newest_of_scope';

    /** The scope does not resolve, so there is no archive path to unlink. */
    case UNKNOWN_SCOPE = 'unknown_scope';

    /** The timestamp does not parse, so the row has no place in the oldest-first order. */
    case UNDATED = 'undated';

    /** Retention pin: the operator asked for this one to stay. */
    case PINNED = 'pinned';

    /** Shipping is configured and the last copy attempt did not succeed: this is still the only copy. */
    case AWAITING_SHIPMENT = 'awaiting_shipment';

    /**
     * How this guard is named in the agent's log line.
     *
     * @return string Operator-facing label
     */
    public function logLabel(): string
    {
        return match ($this) {
            self::ERROR_RECORD => 'error record',
            self::NEWEST_OF_SCOPE => 'newest of scope',
            self::UNKNOWN_SCOPE => 'unknown scope',
            self::UNDATED => 'undated',
            self::PINNED => 'pinned',
            self::AWAITING_SHIPMENT => 'awaiting shipment',
        };
    }
}
