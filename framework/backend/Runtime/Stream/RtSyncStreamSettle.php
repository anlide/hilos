<?php

declare(strict_types=1);

namespace Hilos\Runtime\Stream;

use Hilos\Core\Daemon\SignalDispatch;
use Closure;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use WeakMap;

/**
 * The answer a streamed operation is owed while a master's dispatch carries it as an ordinary
 * signal: the carrier the channel built is the key, and the dispatch settles it once it applied
 * the operation and queued its further delivery.
 *
 * A carrier the dispatch dropped without settling - a refusal, a failure, a dispatch that never
 * ran - is answered refused when the carrier itself goes, so an operation is never left awaiting
 * an answer and never read as applied.
 *
 * Framework-first: process-local bookkeeping of the stream, held weakly by the carrier it
 * answers for; nothing else reads it and nothing replicates it.
 */
final class RtSyncStreamSettle
{
    /** @var ?WeakMap<RtSyncSignalDataInterface, self> Answers owed, by the carrier they are owed for */
    private static ?WeakMap $owed = null;

    private bool $settled = false;

    /**
     * @param Closure(bool, ?SignalDispatch): void $settle The channel's answer: true once applied, false when refused
     */
    private function __construct(private readonly Closure $settle)
    {
    }

    /** A carrier gone unsettled was never applied. */
    public function __destruct()
    {
        $this->answer(false);
    }

    /**
     * Owes the carrier's channel an answer until the dispatch settles it.
     *
     * @param RtSyncSignalDataInterface $carrier Carrier the dispatch will walk
     * @param Closure(bool, ?SignalDispatch): void $settle The channel's answer
     */
    public static function await(RtSyncSignalDataInterface $carrier, Closure $settle): void
    {
        self::$owed ??= new WeakMap();
        self::$owed[$carrier] = new self($settle);
    }

    /**
     * Answers the carrier's channel, when it is owed an answer at all; a carrier no channel
     * awaits - a local write, a replica - is left alone.
     *
     * @param RtSyncSignalDataInterface $carrier Carrier the dispatch walked
     * @param bool $applied Whether the operation was applied and its further delivery queued
     * @param ?SignalDispatch $origin Causal operation, or null for an independent fact
     */
    public static function settle(RtSyncSignalDataInterface $carrier, bool $applied, ?SignalDispatch $origin = null): void
    {
        $owed = self::$owed[$carrier] ?? null;
        if ($owed === null) {
            return;
        }
        unset(self::$owed[$carrier]);
        $owed->answer($applied, $origin);
    }

    /**
     * @param bool $applied Whether the operation was applied
     * @param ?SignalDispatch $origin Causal operation, or null for an independent fact
     */
    private function answer(bool $applied, ?SignalDispatch $origin = null): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        ($this->settle)($applied, $origin);
    }
}
