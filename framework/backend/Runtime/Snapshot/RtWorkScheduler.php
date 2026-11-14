<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Hilos\Core\Exception\InvalidArgumentException;

/**
 * One owner starts a pass; consumers reuse budget(), including across connections. The master's
 * dispatch round and a worker's RT pass each own one: the stream with the ordinary share, the
 * process arena's retirement with the retirement share.
 */
final class RtWorkScheduler
{
    private const int SHARE_PARTS = 8;
    private const int ORDINARY_PARTS = 4;
    private const int SNAPSHOT_PARTS = 3;

    /** @var array<string, RtWorkBudget> The three lanes of the current pass */
    private array $lanes = [];

    /**
     * @param int $steps Total process allowance per RT pass
     * @param int $bytes Total logical bytes per RT pass
     * @throws InvalidArgumentException When an allowance is negative
     */
    public function __construct(private readonly int $steps = RtWorkBudget::DEFAULT_STEPS, private readonly int $bytes = RtWorkBudget::DEFAULT_BYTES)
    {
        $this->beginPass();
    }

    /**
     * Called once by the process loop, never once per consumer.
     *
     * @throws InvalidArgumentException When an allowance is negative
     */
    public function beginPass(): void
    {
        $free = new RtWorkBudget(0, 0);
        $ordinarySteps = intdiv($this->steps * self::ORDINARY_PARTS, self::SHARE_PARTS);
        $snapshotSteps = intdiv($this->steps * self::SNAPSHOT_PARTS, self::SHARE_PARTS);
        $ordinaryBytes = intdiv($this->bytes * self::ORDINARY_PARTS, self::SHARE_PARTS);
        $snapshotBytes = intdiv($this->bytes * self::SNAPSHOT_PARTS, self::SHARE_PARTS);
        foreach ($this->lanes as $lane) {
            $lane->close();
        }
        $this->lanes = [
            RtWorkLane::Ordinary->name => new RtWorkBudget($ordinarySteps, $ordinaryBytes, $free),
            RtWorkLane::Snapshot->name => new RtWorkBudget($snapshotSteps, $snapshotBytes, $free),
            RtWorkLane::Retire->name => new RtWorkBudget(
                $this->steps - $ordinarySteps - $snapshotSteps,
                $this->bytes - $ordinaryBytes - $snapshotBytes,
                $free,
            ),
        ];
    }

    /**
     * @param RtWorkLane $lane Consumer's role
     * @return RtWorkBudget The SAME allowance for every consumer in this lane and pass
     */
    public function budget(RtWorkLane $lane): RtWorkBudget
    {
        return $this->lanes[$lane->name];
    }

    /**
     * @param RtWorkLane $lane Lane that has no more runnable work in this pass
     */
    public function finish(RtWorkLane $lane): void
    {
        $this->lanes[$lane->name]->close();
    }
}
