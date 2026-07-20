<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupScheduleMechanism - which evaluator owns a backup schedule entry.
 *
 * Each entry is evaluated by exactly one mechanism, so a single scheduled time can only
 * fire once (the runtime concurrency lock is a backstop, not the primary guard). The
 * default is {@see AGENT}: it needs no project wiring, matching the framework-owned backup
 * activation. Both mechanisms converge on the same guarded create path on the monopoly
 * {@see Agent\BackupAgent}.
 */
enum BackupScheduleMechanism: string
{
    /** The backup agent owns the cron rule and evaluates it on the cluster leader in onTick. */
    case AGENT = 'agent';

    /** The daemon owns the cron rule; its named DAEMON/CRON signal drives the backup agent. */
    case DAEMON = 'daemon';

    /**
     * Parses a stored mechanism value, defaulting a missing one to {@see AGENT}.
     *
     * @param ?string $value Raw mechanism value from the schedule entry
     * @return ?self Matched mechanism, {@see AGENT} when absent, or null when unrecognized
     */
    public static function fromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return self::AGENT;
        }

        return self::tryFrom($value);
    }
}
