<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;

/**
 * Puts the resolved write level into the logger, and says so once per change (HIL-761).
 *
 * The one place that writes {@see Logger::setWriteLevel()}, so the three moments the level can
 * change - the process starting on its environment, the settings becoming readable, an edit
 * arriving from another process - all end up saying the same thing the same way.
 *
 * Static, and holding the resolver between calls, because the complaint about an unusable
 * setting is raised once per change of outcome: a resolver built fresh on every incoming frame
 * would have nothing to compare against and would complain on every one of them.
 */
final class LogWriteLevelApplier
{
    /** @var ?LogWriteLevelResolver Reader of the setting, kept so its complaint stays deduplicated */
    private static ?LogWriteLevelResolver $resolver = null;

    /** @var ?LogWriteLevelListenerInterface Told about a real change, when this process has someone to tell */
    private static ?LogWriteLevelListenerInterface $listener = null;

    /**
     * Sets the level from the environment alone.
     *
     * Run first in every process that logs, before the first line of the journal: the
     * environment is readable long before the database is, and a name that is not a level falls
     * back to INFO rather than refusing to start - a process that cannot read its own level
     * still has to log.
     */
    public static function applyFromEnv(): void
    {
        self::apply(LogWriteLevelResolver::fromEnv(), 'source: ' . LogWriteLevelResolver::SOURCE_ENV);
    }

    /**
     * Sets the level from the settings, falling back to the environment when they cannot answer.
     *
     * Run once the settings are reachable, and again on every edit that arrives afterwards. The
     * source is named by the road actually taken, so a fallback does not report itself as a
     * setting; the complaint explaining the fallback goes to the journal beside it.
     */
    public static function applyFromSettings(): void
    {
        $resolver = self::$resolver ??= new LogWriteLevelResolver();
        $level = $resolver->resolve();

        while (($complaint = $resolver->takeComplaint()) !== null) {
            Logger::error($complaint);
        }

        self::apply($level, 'source: ' . $resolver->lastSource());
    }

    /**
     * Sets the level a worker reported, in the master process.
     *
     * The master's own way in. It reads no setting of its own - it is forbidden the database -
     * so the level it writes daemon.log at is whatever a worker last told it, and the worker
     * that told it is named in the journal instead of a source, because that is the honest
     * answer to "where did this come from" here.
     *
     * @param LogLevel $level Level the worker writes from
     * @param int $workerIndex Index of the worker that reported it
     */
    public static function applyReported(LogLevel $level, int $workerIndex): void
    {
        self::apply($level, "reported by worker #{$workerIndex}");
    }

    /**
     * Sets the listener told about a real change of level, or clears it.
     *
     * @param ?LogWriteLevelListenerInterface $listener Receiver of every change, or null for none
     */
    public static function setListener(?LogWriteLevelListenerInterface $listener): void
    {
        self::$listener = $listener;
    }

    /**
     * Forgets the resolver and the listener, returning the applier to its untouched state.
     *
     * For tests, and for a process that ran a whole lifecycle in-process; nothing in the running
     * spine calls it.
     */
    public static function reset(): void
    {
        self::$resolver = null;
        self::$listener = null;
    }

    /**
     * Puts a level into the logger, announcing it only when it is not the one already in force.
     *
     * The silence on a repeat is what keeps a node with several workers from writing the same
     * line once per worker, and what keeps the master quiet when its second worker reports the
     * level its first one already did.
     *
     * @param LogLevel $level Level to write from
     * @param string $origin Where the value came from, as the journal line's parenthetical
     */
    private static function apply(LogLevel $level, string $origin): void
    {
        $changed = Logger::writeLevel() !== $level;
        Logger::setWriteLevel($level);

        if (!$changed) {
            return;
        }

        Logger::logWriteLevelChange("Log write level set to {$level->value} ({$origin})");
        self::$listener?->onWriteLevelChanged($level);
    }
}
