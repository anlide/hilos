<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Bulk;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableBulkUntouchedDTO;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\TableConstants;

/**
 * One mass operation in flight, kept where the tick that drives it can pick it up.
 *
 * Everything a run would have carried on the handler's own stack, held as a record instead:
 * the worker is single-threaded, so looping over forty rows inside the action handler would
 * stop every other connection this worker serves. The record is a record for the same reason
 * the parked action in `framework/backend/Core/Page/DeferredAction.php` is - a closure would
 * hide the state a later tick has to read and a test has to assert.
 *
 * It holds no page instance and no connection, only their names: the page is resolved by the
 * router that owns the pool, and the table layer does not reach up into the page layer. The
 * table itself it does hold, because a table is of this layer and the run asks it for the
 * next window and for whether a row still belongs to the set.
 *
 * The target is one of two and never both, exactly as the request was: the named rows sit in
 * {@see self::$queue} from the start, while a condition is refilled a window at a time from
 * {@see self::$cursor}. A condition is walked by anchor rather than by offset because the run
 * itself changes the set it walks, and an offset would step over the rows that moved up.
 */
final class TableBulkRun
{
    /** @var list<string> Rows fetched and not yet handed out, in the order they are judged */
    public array $queue;

    /** @var array<string, float> Rows handed out and not yet judged, with the moment each verdict is owed by */
    public array $awaiting = [];

    /** @var list<TableBulkUntouchedDTO> Rows reached and left alone, up to the name ceiling */
    public array $untouched = [];

    /** @var int Untouched rows past the name ceiling, counted rather than named */
    public int $untouchedOmitted = 0;

    /** @var int Rows the run changed */
    public int $touched = 0;

    /** @var int Rows the run has judged, changed and left alike - the number the bar moves by */
    public int $judged = 0;

    /** @var ?TableAnchorDTO Place the last fetched window ended at, or null before the first one */
    public ?TableAnchorDTO $cursor = null;

    /** @var bool Whether the target has nothing left to fetch */
    public bool $drained;

    /**
     * Opens one bulk run over a target.
     *
     * @param string $progressKey Key naming this run - its reply, its bar and its report all carry it
     * @param string $acceptKey Connection that started it, and the only one its frames are addressed to
     * @param string $page Page the table belongs to, as the wire names it
     * @param string $tableKey Table the run is over
     * @param ViewportTable $table Table itself, asked for the next window and for membership
     * @param string $action Action name the page judges rows under
     * @param ?list<string> $rowKeys Rows named one by one, or null when the target is a condition
     * @param ?array<string, mixed> $filter Condition describing the rows, or null when they are named
     * @param ?int $total Rows the run will judge, or null when the set has no honest count
     */
    public function __construct(
        public readonly string $progressKey,
        public readonly string $acceptKey,
        public readonly string $page,
        public readonly string $tableKey,
        public readonly ViewportTable $table,
        public readonly string $action,
        public readonly ?array $rowKeys,
        public readonly ?array $filter,
        public readonly ?int $total,
    ) {
        $this->queue = $rowKeys ?? [];
        $this->drained = $rowKeys !== null;
    }

    /**
     * Tells whether the run has judged everything it will ever judge.
     *
     * All three have to be empty, and the awaiting map is the one most easily forgotten: a
     * target with nothing left to hand out is still unfinished while a verdict is owed, and
     * reporting there would name rows as untouched that are about to be touched.
     *
     * @return bool Whether nothing is left to fetch, to hand out, or to wait for
     */
    public function isFinished(): bool
    {
        return $this->drained && $this->queue === [] && $this->awaiting === [];
    }

    /**
     * Hands one row out for judging and starts the clock on its verdict.
     *
     * @param string $rowKey Row handed to the page
     * @param float $deadline Moment the verdict is owed by, in unix seconds
     */
    public function handOut(string $rowKey, float $deadline): void
    {
        $this->awaiting[$rowKey] = $deadline;
    }

    /**
     * Takes one row off the waiting list, if it is on it.
     *
     * @param string $rowKey Row a verdict arrived for
     * @return bool Whether the run was in fact waiting on that row
     */
    public function settle(string $rowKey): bool
    {
        if (!isset($this->awaiting[$rowKey])) {
            return false;
        }

        unset($this->awaiting[$rowKey]);

        return true;
    }

    /**
     * Takes off the waiting list every row whose verdict is late, and names them.
     *
     * The clock lives on the record rather than in the sweep so that the rule can be asked
     * about without waiting out the timeout: what is decided here is which rows the owner has
     * run out of time on, and what follows from that - a reason, a line in the log, a bar that
     * moves - belongs to the sweep that calls this.
     *
     * @param float $now Moment to judge the deadlines against, in unix seconds
     * @return list<string> Rows whose verdict never came in time, in the order they were handed out
     */
    public function overdue(float $now): array
    {
        $late = [];
        foreach ($this->awaiting as $rowKey => $deadline) {
            if ($deadline <= $now) {
                $late[] = $rowKey;
                unset($this->awaiting[$rowKey]);
            }
        }

        return $late;
    }

    /**
     * Records that the page changed one row.
     */
    public function recordTouched(): void
    {
        $this->touched++;
        $this->judged++;
    }

    /**
     * Records that one row was left alone, and why.
     *
     * Past {@see TableConstants::BULK_UNTOUCHED_NAME_CEILING} the name is dropped and only
     * counted. Ten thousand names in one frame are the forbidden silent partial success in
     * another form - no reader gets through them - while the count still says the report is
     * not the whole list.
     *
     * @param string $rowKey Row that was left alone
     * @param string $reason Why it was left, in words meant for the person who asked
     */
    public function recordUntouched(string $rowKey, string $reason): void
    {
        if (count($this->untouched) < TableConstants::BULK_UNTOUCHED_NAME_CEILING) {
            $this->untouched[] = new TableBulkUntouchedDTO($rowKey, $reason);
        } else {
            $this->untouchedOmitted++;
        }
        $this->judged++;
    }
}
