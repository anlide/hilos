<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Generator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use LogicException;
use Throwable;

/**
 * One source's snapshot read out in portions, from a single cut: the rows that stood in the source
 * at that revision, with the values they were committed with, in insertion order. Whatever the
 * source does after the cut does not reach this read; a later write is a later revision.
 *
 * It holds pages of the version only - no context, collection or row object - so a source that is
 * unmounted or dropped meanwhile is not kept alive by an unfinished read. Each advance takes at
 * most the given allowance of steps and bytes, and stops inside a row when the allowance ends.
 */
final class RtSnapshotExport
{
    private ?RtIndexCursor $cursor;
    private ?RtWorkDriver $step = null;
    private bool $finished = false;

    /**
     * @param int $revision Committed revision the cut was taken at
     * @param ?RtVersionCut $cut Pinned version to read, null for a source holding no row in it
     * @param RtSnapshotScope $scope Rows the read covers
     * @param list<string> $ids Rows read beside the cut, as a standalone item is, already within the scope
     * @param list<RtRowValues> $values Their values, at the same positions
     * @throws LogicException When the cut has already closed
     */
    public function __construct(
        private readonly int $revision,
        ?RtVersionCut $cut,
        private readonly RtSnapshotScope $scope,
        private array $ids = [],
        private array $values = [],
    ) {
        $this->cursor = $cut?->rows();
        $cut?->cancel();
    }

    /** An abandoned read releases its pages through their arena. */
    public function __destruct()
    {
        $this->cancel();
    }

    /** @return int Committed revision this read was cut at */
    public function revision(): int
    {
        return $this->revision;
    }

    /**
     * Reads on until the allowance ends or the source does.
     *
     * @param RtWorkBudget $budget Allowance of this pass
     * @return ?RtSnapshotPortion Rows read in this pass, null when this pass completed none; {@see self::isFinished()} tells the end
     * @throws InvalidFormatException When a stored row identity is malformed
     * @throws LogicException When the version's pages closed under the read
     * @throws InvalidArgumentException When the allowance refuses a request
     */
    public function advance(RtWorkBudget $budget): ?RtSnapshotPortion
    {
        $ids = [];
        $values = [];
        $bytes = $budget->usedBytes();
        while (!$this->finished) {
            if ($this->ids !== []) {
                if ($budget->take() === null) {
                    break;
                }
                $ids[] = array_shift($this->ids);
                $values[] = array_shift($this->values);
                continue;
            }
            if ($this->cursor === null) {
                $this->finished = true;
                break;
            }
            $this->step ??= new RtWorkDriver($this->nextRow($this->cursor));
            try {
                $this->step->advance($budget);
            } catch (InvalidFormatException | LogicException $refused) {
                $this->cancel();
                throw $refused;
            } catch (Throwable $unexpected) {
                $this->cancel();
                throw new LogicException('The RT snapshot read failed: ' . $unexpected->getMessage(), 0, $unexpected);
            }
            if (!$this->step->isFinished()) {
                break;
            }
            $row = $this->step->result();
            $this->step = null;
            if ($row === null) {
                $this->finished = true;
                $this->cursor->cancel();
                $this->cursor = null;
            } elseif ($row !== false) {
                [$ids[], $values[]] = $row;
            }
        }

        return $ids === [] ? null : new RtSnapshotPortion($ids, $values, $budget->usedBytes() - $bytes);
    }

    /** @return bool Whether every row of the cut within the scope has been read out */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /** Ends the read at once; its pages retire through their arena. */
    public function cancel(): void
    {
        $this->step?->cancel();
        $this->step = null;
        $this->cursor?->cancel();
        $this->cursor = null;
        $this->ids = [];
        $this->values = [];
    }

    /**
     * @param RtIndexCursor $cursor Insertion-order membership of the cut
     * @return Generator<int, RtWork, int, array{string, RtRowValues}|false|null> Next row within the scope, false for
     *     one outside it, null at the end
     * @throws InvalidFormatException When a stored identity is malformed
     * @throws LogicException When a page closed
     */
    private function nextRow(RtIndexCursor $cursor): Generator
    {
        $entry = yield from $cursor->advance();
        if ($entry === null) {
            return null;
        }
        $arena = $entry->reader()->arena();
        $record = new RtPreparedRow($arena, new RtRowValues($arena, $entry->rootIn($arena)->first, RtRowRole::Transient));
        $identity = $record->identity();
        $id = yield from self::text($identity->id());
        $set = $identity->set();
        $setKey = $set === null ? null : yield from self::text($set->id());

        return $this->scope->admits($id, $setKey) ? [$id, $record->values()] : false;
    }

    /**
     * @param RtRowValues $text Segmented key
     * @return Generator<int, RtWork, int, string> The key, its bytes charged as they are read
     * @throws InvalidFormatException When the value is not text
     * @throws LogicException When the lease closed
     */
    private static function text(RtRowValues $text): Generator
    {
        $out = '';
        $cursor = $text->reader()->text();
        while (($part = yield from $cursor->advance()) !== null) {
            $left = strlen($part);
            while ($left > 0) {
                $left -= yield new RtWork($left);
            }
            $out .= $part;
        }

        return $out;
    }
}
