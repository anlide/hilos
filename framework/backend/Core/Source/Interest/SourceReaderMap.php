<?php

declare(strict_types=1);

namespace Hilos\Core\Source\Interest;

use Hilos\Core\Source\SourceChange;
use Hilos\TruthSource\RtNodeSourceMap;

/**
 * Who holds a collection, as far as the process doing the sending knows.
 *
 * The addressing half of what {@see RtNodeSourceMap} does for ownership, and built the same way
 * and for the same reason: the answer lives in another process, so it has to be reported rather
 * than read, and what arrives is kept here beside the sender. Where the two differ is the
 * question - that map says whether a frame may be believed, this one says whether it is worth
 * sending at all.
 *
 * One class with two instances, because the two levels of the same fan-out ask exactly this: the
 * daemon master holds one keyed by worker, the peer server holds one keyed by node. A holder is
 * therefore a plain string and the caller says what it means; nothing here needs to know which
 * of the two it is looking at.
 *
 * A holder's list is replaced whole ({@see note()}), never merged. What is reported is the whole
 * of what that holder reads, so a key absent from a later report is a key it stopped reading -
 * and merging would keep sending frames for it until the holder died.
 */
final class SourceReaderMap
{
    /** @var array<string, array<string, list<string>>> [holder id => [kind => collection keys]] */
    private array $byHolder = [];

    /**
     * Records everything one holder reads, replacing whatever it read before.
     *
     * The returned keys are what the caller owes the holder an initial state for: a collection
     * already on its list is one it already holds a copy of, and sending it again would undo
     * whatever deltas landed since.
     *
     * @param string $holderId Worker or node reporting what it reads
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param list<string> $collectionKeys Every collection it reads of that kind
     * @return list<string> Those of them this holder did not read before
     */
    public function note(string $holderId, string $kind, array $collectionKeys): array
    {
        $held = $this->byHolder[$holderId][$kind] ?? [];

        $added = [];
        foreach ($collectionKeys as $collectionKey) {
            if (!in_array($collectionKey, $held, true)) {
                $added[] = $collectionKey;
            }
        }

        if ($collectionKeys === []) {
            unset($this->byHolder[$holderId][$kind]);
            if (($this->byHolder[$holderId] ?? []) === []) {
                unset($this->byHolder[$holderId]);
            }

            return $added;
        }

        $this->byHolder[$holderId][$kind] = $collectionKeys;

        return $added;
    }

    /**
     * Drops everything one holder read, because it is gone.
     *
     * A worker that dies never reports its own departure, and an entry outliving the process
     * behind it is the same wrong answer {@see RtNodeSourceMap::release()} exists to prevent -
     * frames addressed to a reader that is not there.
     *
     * @param string $holderId Worker or node whose link closed
     */
    public function release(string $holderId): void
    {
        unset($this->byHolder[$holderId]);
    }

    /**
     * @param string $holderId Worker or node to ask about
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey Collection to ask about
     * @return bool True when that holder reads it
     */
    public function holds(string $holderId, string $kind, string $collectionKey): bool
    {
        return in_array($collectionKey, $this->byHolder[$holderId][$kind] ?? [], true);
    }

    /**
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey Collection to ask about
     * @return list<string> Holders that read it, in the order they first reported
     */
    public function holders(string $kind, string $collectionKey): array
    {
        $holders = [];
        foreach ($this->byHolder as $holderId => $byKind) {
            if (in_array($collectionKey, $byKind[$kind] ?? [], true)) {
                $holders[] = (string)$holderId;
            }
        }

        return $holders;
    }

    /**
     * Returns everything any holder of this map reads, each collection named once.
     *
     * The union rather than a holder's own list, because that is what the level above asks for:
     * a node tells the mesh what it reads as one list, and which of its workers is behind a key
     * is nobody else's business - the answer would be the same for the sender either way.
     *
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @return list<string> Collections at least one holder reads, in the order they first appeared
     */
    public function collections(string $kind): array
    {
        $collections = [];
        foreach ($this->byHolder as $byKind) {
            foreach ($byKind[$kind] ?? [] as $collectionKey) {
                if (!in_array($collectionKey, $collections, true)) {
                    $collections[] = $collectionKey;
                }
            }
        }

        return $collections;
    }
}
