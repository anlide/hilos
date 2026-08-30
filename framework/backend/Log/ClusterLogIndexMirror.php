<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Pages\Logs\AbstractHilosLogsPage;

/**
 * The log section's copy of the cluster picture, and its count of who is looking at it (HIL-756).
 *
 * What {@see AbstractHilosLogsAgent} keeps between frames: the picture as {@see LogAggregatorAgent}
 * last described it, so the pages of the section answer out of memory instead of walking a
 * directory - which they could only ever walk on their own node anyway.
 *
 * Static, the way the overview page's subscriber set is ({@see AbstractHilosLogsPage}): the mirror
 * belongs to the worker process and not to one page dispatch, and every page of the section reads
 * the same copy. There is one agent of this kind in the cluster, so there is one mirror.
 *
 * The count is of the SECTION and not of one page: it is what the aggregator is told, and the
 * aggregator has no interest in which screen the viewers are on - it hands over the whole picture
 * either way.
 *
 * Nothing here reaches out for anything. Frames arrive and are filed; the count is moved by the
 * pages as connections come and go. Whether the mirror has ever been written to is a question the
 * overview must be able to ask ({@see known()}), because "no picture has arrived" is a different
 * thing to show than "no node can read its logs", and both are different from zero.
 */
final class ClusterLogIndexMirror
{
    /**
     * @var ?ClusterLogIndex The picture as it was last described, or null before the first frame
     *
     * The null is the third state itself and not an empty picture standing in for it: an empty
     * {@see ClusterLogIndex} means the aggregator answered and had no node to report, where this
     * means nobody has answered at all.
     */
    private static ?ClusterLogIndex $index = null;

    /** @var array<string, true> Accept keys of the connections watching any page of the section */
    private static array $viewers = [];

    /**
     * Counts one more connection as watching the section.
     *
     * Idempotent, because it is called from the page's subscribe and a browser may resubscribe over
     * a connection it already holds; keyed by accept key, a second call is the same viewer.
     *
     * @param string $acceptKey Accept key of the watching connection
     */
    public static function addViewer(string $acceptKey): void
    {
        self::$viewers[$acceptKey] = true;
    }

    /**
     * Stops counting one connection as watching the section.
     *
     * @param string $acceptKey Accept key of the connection that left
     */
    public static function removeViewer(string $acceptKey): void
    {
        unset(self::$viewers[$acceptKey]);
    }

    /**
     * @return int How many connections are watching the section right now
     */
    public static function viewerCount(): int
    {
        return count(self::$viewers);
    }

    /**
     * @return list<string> Accept keys of the watching connections, for reconciling against the roster
     */
    public static function viewerKeys(): array
    {
        return array_keys(self::$viewers);
    }

    /**
     * Files one frame from the aggregator.
     *
     * A snapshot REPLACES the picture and a portion is laid over it slot by slot, which is the
     * whole reason the frame carries that flag: a portion applied as a picture would drop every
     * node it did not mention, and a picture applied as a portion would keep a node that is gone.
     * Either way a slot is exchanged whole and never merged - the decision the frames beneath this
     * one are built on (HIL-754/755), and what makes a lost frame repair itself with the next.
     *
     * @param ClusterLogIndexPortionSignalData $portion Frame as the aggregator sent it
     */
    public static function applyPortion(ClusterLogIndexPortionSignalData $portion): void
    {
        if ($portion->snapshot) {
            self::$index = $portion->toIndex();

            return;
        }

        $index = self::$index ?? ClusterLogIndex::empty();
        foreach ($portion->toSlots() as $slot) {
            $index = $index->withNode($slot);
        }
        self::$index = $index;
    }

    /**
     * @return ?ClusterLogIndex The cluster picture, or null while none has arrived
     */
    public static function index(): ?ClusterLogIndex
    {
        return self::$index;
    }

    /**
     * @return bool Whether a picture has ever arrived, however empty it turned out to be
     */
    public static function known(): bool
    {
        return self::$index !== null;
    }

    /**
     * Forgets the picture, returning the mirror to the state it starts in.
     *
     * For the agent's stop and for tests, and for nothing else. A viewer leaving deliberately does
     * NOT come through here: the picture outlives the last viewer so that the next one sees the
     * last known figures at once and the fresh ones a moment later, where forgetting would flash an
     * empty screen at every entry (HIL-756 Flow).
     */
    public static function forgetPicture(): void
    {
        self::$index = null;
    }
}
