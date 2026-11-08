<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Generator;

/** Exact finite report values; readers share immutable paths and never retain the mutable source map. */
final class RtSourceReports
{
    private ?RtSourcePlan $plan = null;

    /** @param ?RtSourceReportNode $root Immutable original cohort, absent for an empty report set */
    public function __construct(private readonly ?RtSourceReportNode $root = null)
    {
    }

    /**
     * @param int $position Original agent position, preserved on overwrite
     * @param RtSourceReport $report Immutable values of the replacement
     * @return self New report set; old readers keep their exact values
     */
    public function withReport(int $position, RtSourceReport $report): self
    {
        return new self(RtSourceReportNode::put($this->root, $position, $report));
    }

    /**
     * @param int $position Departed agent position
     * @return self New report set; a rejoin receives a new position from its owner
     */
    public function withoutReport(int $position): self
    {
        return new self(RtSourceReportNode::remove($this->root, $position));
    }

    /** @return Generator<int, RtSourceReport> Original report values in original agent order */
    public function reports(): Generator
    {
        return self::orderedReports($this->root);
    }

    /**
     * Sequential FIFO readers reuse the completed fold. The plan retains only a root cursor,
     * never this snapshot wrapper, so memoization introduces no reference cycle.
     *
     * @return RtSourcePlan One portioned fold for this exact immutable basis
     */
    public function plan(): RtSourcePlan
    {
        if ($this->plan !== null && $this->plan->ready) {
            return $this->plan;
        }
        return $this->plan = new RtSourcePlan($this->reports());
    }

    /**
     * @param ?RtSourceReportNode $node Original immutable subtree
     * @return Generator<int, RtSourceReport> One report after at most O(log cohort) scalar stack transitions
     */
    private static function orderedReports(?RtSourceReportNode $node): Generator
    {
        if ($node === null) {
            return;
        }
        yield from self::orderedReports($node->left);
        yield $node->position => $node->report;
        yield from self::orderedReports($node->right);
    }
}
