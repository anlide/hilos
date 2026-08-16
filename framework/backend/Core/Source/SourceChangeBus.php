<?php

declare(strict_types=1);

namespace Hilos\Core\Source;

use Closure;
use Hilos\Database\DbSyncApplicator;
use Hilos\HilosException;
use Hilos\Runtime\RtSyncApplicator;

/**
 * The one place a collection announces that its membership changed.
 *
 * The announcement is made by the store itself, not by the roads leading to it, so both roads -
 * the collection actions and the applicator of an incoming sync - are covered for free and
 * forgetting to announce becomes impossible. Subscribers are called synchronously, in
 * registration order, because the first of them repairs a view that must not answer a read with
 * a row the store no longer holds.
 *
 * Static like {@see RtSyncApplicator} and {@see DbSyncApplicator} rather than a facade global:
 * facade globals hold project data and are configured by the project, and this is core machinery
 * no project configures.
 */
final class SourceChangeBus
{
    /** @var list<SourceChangeSubscriberInterface> Subscribers in the order they were registered */
    private static array $subscribers = [];

    /** @var SourceChangeProvenance Origin of the write currently running */
    private static SourceChangeProvenance $provenance = SourceChangeProvenance::LocalWrite;

    /**
     * Registers one subscriber, after every subscriber registered before it.
     *
     * @param SourceChangeSubscriberInterface $subscriber Receiver of every announced change
     */
    public static function subscribe(SourceChangeSubscriberInterface $subscriber): void
    {
        self::$subscribers[] = $subscriber;
    }

    /**
     * Announces one collection mutation to every subscriber.
     *
     * A subscriber that raises is not silenced: a swallowed error here is a sync that vanished
     * without a trace, which costs more than the write it interrupts.
     *
     * @param SourceChange $change Fact describing what happened to the source
     * @throws HilosException Whatever a subscriber's reaction raises
     */
    public static function publish(SourceChange $change): void
    {
        foreach (self::$subscribers as $subscriber) {
            $subscriber->onSourceChange($change, self::$provenance);
        }
    }

    /**
     * Runs a write that applies another process's change, so nothing rebroadcasts it.
     *
     * The previous provenance is restored rather than assumed to be a local write, so an
     * applicator called from inside another applied write does not hand the rest of the outer
     * write back to the broadcasters.
     *
     * @param Closure $write Write to run with an applied-remote provenance
     * @throws HilosException Whatever the wrapped write raises
     */
    public static function whileApplyingRemote(Closure $write): void
    {
        $previous = self::$provenance;
        self::$provenance = SourceChangeProvenance::AppliedRemote;
        try {
            $write();
        } finally {
            self::$provenance = $previous;
        }
    }

    /**
     * Drops every subscriber and returns the provenance to a local write.
     *
     * Used by facade init(), which registers the framework subscribers from scratch on every
     * call, and by tests that install a subscriber of their own.
     */
    public static function reset(): void
    {
        self::$subscribers = [];
        self::$provenance = SourceChangeProvenance::LocalWrite;
    }
}
