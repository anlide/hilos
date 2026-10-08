<?php

declare(strict_types=1);

namespace Hilos\Core\Source;

use Closure;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\Database;
use Hilos\Database\DbSyncApplicator;
use Hilos\HilosException;
use Hilos\Runtime\RtSyncApplicator;
use Throwable;

/**
 * The one place a collection announces that its membership changed.
 *
 * The announcement is made by the store itself, not by the roads leading to it, so both roads -
 * the collection actions and the applicator of an incoming sync - are covered for free and
 * forgetting to announce becomes impossible. Subscribers are called synchronously, in
 * registration order, because the first of them repairs a view that must not answer a read with
 * a row the store no longer holds.
 *
 * A change made under an open transaction - to a database row or to a runtime state alike, one
 * transaction covering both - reaches the two kinds of subscriber at two moments. A mirror
 * ({@see SourceMirrorSubscriberInterface}) hears it at the write, as it must: it repairs this
 * process's memory, which the code still inside the transaction reads. A reaction hears it when
 * the transaction commits, and not at all when it rolls back - told at the write, it would act on
 * a change that may never stand. Outside a transaction the two moments are one, and the order is
 * the registration order as before. A rollback tells the mirrors what it put back
 * ({@see self::publishToMirrors()}); the reactions never heard the change, and hear nothing.
 *
 * Static like {@see RtSyncApplicator} and {@see DbSyncApplicator} rather than a facade global:
 * facade globals hold project data and are configured by the project, and this is core machinery
 * no project configures.
 */
final class SourceChangeBus
{
    /** @var list<SourceMirrorSubscriberInterface> The mirrors among them, in that order */
    private static array $mirrors = [];

    /** @var list<SourceChangeSubscriberInterface> The reactions among them, in that order */
    private static array $reactions = [];

    /** @var SourceChangeProvenance Origin of the write currently running */
    private static SourceChangeProvenance $provenance = SourceChangeProvenance::LocalWrite;

    /**
     * Registers one subscriber, after every subscriber registered before it.
     *
     * @param SourceChangeSubscriberInterface $subscriber Receiver of every announced change
     */
    public static function subscribe(SourceChangeSubscriberInterface $subscriber): void
    {
        if ($subscriber instanceof SourceMirrorSubscriberInterface) {
            self::$mirrors[] = $subscriber;
        } else {
            self::$reactions[] = $subscriber;
        }
    }

    /**
     * Announces one collection mutation to every subscriber.
     *
     * A subscriber that raises is not silenced: a swallowed error here is a sync that vanished
     * without a trace, which costs more than the write it interrupts. The failure is wrapped so
     * callers of the write can name what may reach them; an already wrapped one is rethrown as
     * it is, because a publish made from inside a reaction would otherwise bury the original one
     * floor deeper.
     *
     * A fact reaches the mirrors now and the reactions once the transaction it was made under
     * commits - now, when there is none - with the provenance the write had at this moment, not
     * the one in force when the commit releases it. A failing reaction released by a commit does
     * not undo it: it reaches the end of the handler inside that commit's
     * AnnouncementFailedException ({@see Database::handlerEnd()}). A runtime fact is no exception:
     * the transaction covers the runtime as it covers the database.
     *
     * @param SourceChange $change Fact describing what happened to the source
     * @throws SourceChangeSubscriberException When a subscriber's reaction fails
     */
    public static function publish(SourceChange $change): void
    {
        $provenance = self::$provenance;
        self::deliver(self::$mirrors, $change, $provenance);
        $reactions = self::$reactions;
        if ($reactions !== []) {
            Database::afterCommit(static function () use ($reactions, $change, $provenance): void {
                self::deliver($reactions, $change, $provenance);
            });
        }
    }

    /**
     * Tells the mirrors alone that a rollback took a change back.
     *
     * The reverse fact of a rollback: what a key holds again, or the update undone. A mirror heard
     * the change at the write and may have built memory on it, so it hears it taken back the same
     * way. A reaction and the other processes never heard the change - theirs was held for the
     * commit and dropped by the rollback - so there is nothing to take back from them.
     *
     * @param SourceChange $change Fact describing what the rollback put back
     * @throws SourceChangeSubscriberException When a mirror's repair fails
     */
    public static function publishToMirrors(SourceChange $change): void
    {
        self::deliver(self::$mirrors, $change, self::$provenance);
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
        self::$mirrors = [];
        self::$reactions = [];
        self::$provenance = SourceChangeProvenance::LocalWrite;
    }

    /**
     * Tells the given subscribers, in order, stopping at the first failure.
     *
     * @param list<SourceChangeSubscriberInterface> $subscribers Subscribers to tell, in registration order
     * @param SourceChange $change Fact describing what happened to the source
     * @param SourceChangeProvenance $provenance Origin of the write, as it stood when the fact was published
     * @throws SourceChangeSubscriberException When a subscriber's reaction fails
     */
    private static function deliver(array $subscribers, SourceChange $change, SourceChangeProvenance $provenance): void
    {
        foreach ($subscribers as $subscriber) {
            try {
                $subscriber->onSourceChange($change, $provenance);
            } catch (SourceChangeSubscriberException $wrapped) {
                throw $wrapped;
            } catch (Throwable $failure) {
                throw new SourceChangeSubscriberException($subscriber::class, $change, $failure);
            }
        }
    }
}
