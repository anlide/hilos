<?php

declare(strict_types=1);

namespace Hilos\Core\Source\Interest;

use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Source\SourceChange;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Who in this process reads which collection, and whether its state has arrived yet.
 *
 * The reading half of what {@see RtTruthSourceRegistry} already answers for
 * writing: that one names the agent allowed to change a collection, this one names everybody
 * who needs to see the change. What the two are for differs in the same way — a claim decides
 * whether a write is legal, an interest decides whether a frame is worth sending and whether a
 * read may be answered at all.
 *
 * Interest is held by a consumer ({@see SourceConsumer}), never by the process: a worker runs
 * pages and agents side by side, they come and go independently, and a collection is only
 * dropped when the last of them lets go. That is also why releasing is by consumer and not by
 * collection - a consumer knows when it ends, and nothing else does.
 *
 * Readiness is separate from interest and not implied by it. A process that has said what it
 * wants still holds nothing until the initial state lands, and answering a read from that gap
 * would hand out an empty collection dressed as an answer. The gap is a state
 * ({@see SourceInterestState}) rather than a wait inside the reader, because closing it is the
 * framework's job and the reading code should not know it existed.
 *
 * All of that is about a process whose copy is DELIVERED, which is the worker and only the
 * worker ({@see self::readsWhatIsDelivered()}). Anywhere else the answer to "may I read this"
 * is yes for everything mounted, because there is no second party whose sending it could be
 * waiting on.
 */
final class SourceInterestRegistry
{
    /**
     * What each consumer reads, by kind.
     *
     * Keyed by consumer first because that is the key everything is removed by: a page that
     * unsubscribes takes its whole row out at once, and what the collections lose is worked out
     * from what is left.
     *
     * @var array<string, array<string, list<string>>> [consumerId => [kind => collection keys]]
     */
    private static array $byConsumer = [];

    /** @var array<string, array<string, SourceInterestState>> [kind => [collection key => state]] */
    private static array $states = [];

    /**
     * Whether this process reads only what somebody sends it.
     *
     * False everywhere but in a worker, and that is not a convenience: a process that mounts a
     * collection and is not sent copies of it IS the collection - the master applies every frame
     * to itself, a CLI command holds what it built, a test holds what it seeded. Asking such a
     * process to declare an interest and then wait for a snapshot would be asking it to wait for
     * itself. Only a worker is handed a copy, and only a worker can therefore be too early.
     */
    private static bool $readsWhatIsDelivered = false;

    /**
     * Declares that this process holds no runtime collection until a master sends it one.
     *
     * Said by the worker spine and by nothing else ({@see WorkerManager::run()}). It is what
     * turns the read guard on: before it, every mounted collection answers a read, because
     * whoever mounted it is the one keeping it up to date.
     */
    public static function readsWhatIsDelivered(): void
    {
        self::$readsWhatIsDelivered = true;
    }

    /**
     * Takes that declaration back, for a process that is the origin of its own runtime state.
     *
     * The starting mode, and the counterpart a test needs to put back what it borrowed: the mode
     * is process-wide by nature - it says what kind of process this is - so a case that turns
     * the guard on has to turn it off again or every case after it runs as a worker.
     */
    public static function readsWhatItMounts(): void
    {
        self::$readsWhatIsDelivered = false;
    }

    /**
     * Records that one consumer reads one collection, leaving anything else it reads alone.
     *
     * A collection nobody had asked for starts out {@see SourceInterestState::Declared}: the
     * caller has said what it wants, and what it wants is not here yet. A collection already
     * known keeps the state it had, so a second reader joining a ready collection reads at once
     * rather than waiting for a snapshot the process is already holding.
     *
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey DB collection key or RT collection key
     * @param string $consumerId Consumer holding the interest, named by {@see SourceConsumer}
     */
    public static function register(string $kind, string $collectionKey, string $consumerId): void
    {
        $held = self::$byConsumer[$consumerId][$kind] ?? [];
        if (!in_array($collectionKey, $held, true)) {
            $held[] = $collectionKey;
            self::$byConsumer[$consumerId][$kind] = $held;
        }

        self::$states[$kind][$collectionKey] ??= SourceInterestState::Declared;
    }

    /**
     * Drops everything one consumer read, because it is no longer running here.
     *
     * A collection that loses its last reader loses its record too, so the process is back to
     * having no interest in it rather than to a declared interest nobody holds. The copy itself
     * is dropped by the caller: what is stored where is not this registry's business, and it is
     * the caller that also has to tell the master the list has changed.
     *
     * @param string $consumerId Consumer that ended, named by {@see SourceConsumer}
     */
    public static function releaseConsumer(string $consumerId): void
    {
        $released = self::$byConsumer[$consumerId] ?? [];
        unset(self::$byConsumer[$consumerId]);

        foreach ($released as $kind => $collectionKeys) {
            foreach ($collectionKeys as $collectionKey) {
                if (!self::hasConsumers($kind, $collectionKey)) {
                    unset(self::$states[$kind][$collectionKey]);
                }
            }
        }
    }

    /**
     * Records that the initial state of a collection has landed, so reads may be answered.
     *
     * Silently does nothing for a collection nobody reads any more. The state travels while the
     * consumer that asked for it may already have gone - a page unsubscribing between the
     * request and the answer is the ordinary case - and keeping a readiness with no reader
     * behind it would make {@see isDeclared()} report an interest this process no longer has.
     *
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey DB collection key or RT collection key
     */
    public static function markReady(string $kind, string $collectionKey): void
    {
        if (!isset(self::$states[$kind][$collectionKey])) {
            return;
        }

        self::$states[$kind][$collectionKey] = SourceInterestState::Ready;
    }

    /**
     * Whether a read of this collection may be answered here.
     *
     * Yes to everything in a process that is the origin of its own state - see the mode above;
     * the states below describe a delivery that is not happening there.
     *
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey DB collection key or RT collection key
     * @return bool True when the initial state has landed and reads may be answered
     */
    public static function isReady(string $kind, string $collectionKey): bool
    {
        if (!self::$readsWhatIsDelivered) {
            return true;
        }

        return (self::$states[$kind][$collectionKey] ?? null) === SourceInterestState::Ready;
    }

    /**
     * Whether this process has an interest in a collection at all, ready or not.
     *
     * The question a refused read asks first, because the two halves of the refusal are two
     * different defects: nobody declared the collection means the reader was never wired up,
     * while declared-but-not-ready means it was and the state is late.
     *
     * Answers yes to everything where nothing is delivered, for the reason {@see isReady()} does.
     *
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey DB collection key or RT collection key
     * @return bool True when some consumer here reads it
     */
    public static function isDeclared(string $kind, string $collectionKey): bool
    {
        return self::$readsWhatIsDelivered
            ? isset(self::$states[$kind][$collectionKey])
            : true;
    }

    /**
     * Everything this process reads of one kind, which is what the master is told.
     *
     * The list is the whole of it and replaces whatever was reported before, the way a truth
     * source reports what it owns: a delta would have to be acknowledged to be safe, and there
     * is nothing on this path that acknowledges.
     *
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @return list<string> Collection keys read here, each named once
     */
    public static function collections(string $kind): array
    {
        $collections = [];
        foreach (self::$byConsumer as $byKind) {
            foreach ($byKind[$kind] ?? [] as $collectionKey) {
                if (!in_array($collectionKey, $collections, true)) {
                    $collections[] = $collectionKey;
                }
            }
        }

        return $collections;
    }

    /**
     * Whether anybody here still reads a collection.
     *
     * Asked of the consumer index rather than of the states, and that is the point: after a
     * consumer is released this is what says whether the collection went with it, and the state
     * record has by then already been taken away on this same answer.
     *
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey DB collection key or RT collection key
     * @return bool True when at least one consumer here reads it
     */
    public static function hasConsumers(string $kind, string $collectionKey): bool
    {
        foreach (self::$byConsumer as $byKind) {
            if (in_array($collectionKey, $byKind[$kind] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which consumers here read one collection, by name.
     *
     * The addressed half of {@see hasConsumers()}, and needed by anything that has news about a
     * collection rather than a row of it: telling a page that part of what it reads has stopped
     * being kept up to date starts from this list (HIL-711). Names, because a consumer name is
     * what the caller can turn back into the thing it addresses ({@see SourceConsumer}).
     *
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey DB collection key or RT collection key
     * @return list<string> Consumer names reading it, each named once
     */
    public static function consumersOf(string $kind, string $collectionKey): array
    {
        $consumers = [];
        foreach (self::$byConsumer as $consumerId => $byKind) {
            if (in_array($collectionKey, $byKind[$kind] ?? [], true)) {
                $consumers[] = (string)$consumerId;
            }
        }

        return $consumers;
    }

    /**
     * What one consumer here reads, of one kind.
     *
     * The narrow twin of {@see collections()}, which answers for the whole process. The
     * difference is the whole point where the answer is about one reader: a verdict over
     * everything a page reads cannot be assembled from a per-process list, and news about one
     * collection is not the answer either - a page reading two of them stays affected while
     * either one is (HIL-711).
     *
     * @param string $consumerId Consumer name as {@see SourceConsumer} builds it
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @return list<string> Collection keys that consumer reads
     */
    public static function collectionsOfConsumer(string $consumerId, string $kind): array
    {
        return self::$byConsumer[$consumerId][$kind] ?? [];
    }
}
