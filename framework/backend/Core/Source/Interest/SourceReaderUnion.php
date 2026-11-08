<?php

declare(strict_types=1);

namespace Hilos\Core\Source\Interest;

use Closure;
use Generator;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;

/** One finite report basis, with a reusable complete pair and restartable private preparation. */
final class SourceReaderUnion
{
    public private(set) bool $ready = false;

    /** @var array{rt: list<string>, db: list<string>} Published only after both original name cohorts finish */
    public private(set) array $collections = [SourceChange::KIND_RT => [], SourceChange::KIND_DB => []];

    /**
     * @param Closure(): Generator<int, ?string> $runtime Original RT names, including duplicate and holder steps
     * @param Closure(): Generator<int, ?string> $database Original DB names, including duplicate and holder steps
     */
    public function __construct(private readonly Closure $runtime, private readonly Closure $database)
    {
    }

    /**
     * An abandoned attempt leaves no partial cache. Each factory reopens the same immutable
     * report basis; a completed pair is reused by the next FIFO transition. Names still cost
     * their bytes/hash, array growth and retirement, independently of the visit count.
     *
     * @return Generator<int, void> One original name or holder transition per step
     */
    public function build(): Generator
    {
        if ($this->ready) {
            return;
        }
        $collections = [SourceChange::KIND_RT => [], SourceChange::KIND_DB => []];
        yield;
        foreach ([SourceChange::KIND_RT => $this->runtime, SourceChange::KIND_DB => $this->database] as $kind => $candidates) {
            foreach ($candidates() as $collectionKey) {
                if ($collectionKey !== null) {
                    $collections[$kind][] = $collectionKey;
                }
                yield;
            }
        }
        $this->collections = $collections;
        $this->ready = true;
    }

    /**
     * Preserves ordered strict list comparison, including numeric-looking names. Equality
     * never walks the whole pair inside one step; one string comparison still costs its bytes.
     *
     * @param self $other Complete original pair to compare with this complete pair
     * @return Generator<int, void, void, bool> Whether both ordered pairs match
     * @throws LogicException When either original union has not completed its private preparation
     */
    public function compare(self $other): Generator
    {
        yield;
        if (!$this->ready || !$other->ready) {
            throw new LogicException('Reader unions must be complete before comparison');
        }
        if ($other === $this) {
            return true;
        }
        foreach ($this->collections as $kind => $collections) {
            if (count($collections) !== count($other->collections[$kind])) {
                return false;
            }
            foreach ($collections as $position => $collectionKey) {
                if ($collectionKey !== $other->collections[$kind][$position]) {
                    return false;
                }
                yield;
            }
        }
        return true;
    }
}
