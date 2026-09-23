<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableViewportAnnounceDTO;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableViewportCountDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableViewportOwnCreateDTO;
use Hilos\Core\Table\DTO\TableViewportUnannounceDTO;
use Hilos\Core\Table\DTO\TableWindowFrameDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Core\Table\TableRowPlacement;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for live viewport deltas from BrowserContext::emitBrowserSignals.
 */
final class BrowserContextViewportDeltaTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testInWindowUpdateEmitsRowUpdatedDelta(): void
    {
        $context = $this->boot(
            [new ViewportDeltaUnitRow('alpha', 'Alpha')],
            ['alpha'],
            1,
        );
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        $this->assertSame('alpha', $delta->rowKey);
        $this->assertSame(
            [
                PagePayload::rowKey => 'alpha',
                PagePayload::slots => [
                    ViewportDeltaUnitTable::SLOT => ['key' => 'alpha', 'label' => 'Alpha'],
                ],
            ],
            $delta->row,
        );
    }

    public function testUnchangedRenderedRowEmitsNothing(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha')]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha')], $viewport);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        // Neither a delta nor a count: an update leaves the total alone, and the row
        // came out exactly as it was delivered.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testChangeOutsideTheRenderedRowEmitsNothing(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha')]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha')], $viewport);

        // The source moved a field this table puts in no slot, so the row it rebuilds
        // is the row already on the screen.
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['admin' => true]));
        $context->flushToSignalRouter();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testDeltaRefreshesTheStoredRow(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha')]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Renamed')], $viewport);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Renamed']));
        $context->flushToSignalRouter();

        $this->assertSame(
            [
                PagePayload::rowKey => 'alpha',
                PagePayload::slots => [
                    ViewportDeltaUnitTable::SLOT => ['key' => 'alpha', 'label' => 'Renamed'],
                ],
            ],
            $this->nextDelta()->row,
        );

        // The same change again: the window now holds what was sent, so nothing follows.
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Renamed']));
        $context->flushToSignalRouter();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAFieldNoCellDrawsChangesAndNothingIsSent(): void
    {
        // The users table in small: the row carries an admin flag, and the tab draws the key and
        // the label only. The flag is in the payload, so the whole row did change.
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10, rendered: ['key', 'label']);
        $viewport->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha', false)]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha', true)], $viewport);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['admin' => true]));
        $context->flushToSignalRouter();

        // No delta and no count: nothing the screen draws moved, so a badge here would be one
        // whose Apply changes nothing (HIL-880).
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testADrawnFieldBesideAnUndrawnOneStillSendsTheDelta(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10, rendered: ['key', 'label']);
        $viewport->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha', false)]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Renamed', false)], $viewport);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Renamed']));
        $context->flushToSignalRouter();

        // The row still travels whole: the cut decides whether to send, never what is sent.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        $this->assertSame(
            [
                PagePayload::rowKey => 'alpha',
                PagePayload::slots => [
                    ViewportDeltaUnitTable::SLOT => ['key' => 'alpha', 'label' => 'Renamed', 'admin' => false],
                ],
            ],
            $delta->row,
        );
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testASilencedRowIsRecordedSoItsRepeatTakesTheCheapExit(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10, rendered: ['key', 'label']);
        $viewport->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha', false)]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha', true)], $viewport);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['admin' => true]));
        $context->flushToSignalRouter();
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['admin' => true]));
        $context->flushToSignalRouter();

        // The first change is a changed row and is asked whether it left the set; the silenced
        // row is then what the window remembers, so the same row again is the whole-row match
        // that asks nothing.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $table = Hilos::$table?->get(ViewportDeltaUnitTable::TABLE);
        $this->assertInstanceOf(ViewportDeltaUnitTable::class, $table);
        $this->assertSame(1, $table->setQuestions);
    }

    public function testARowMovedByAFieldNoCellDrawsStillMoves(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Tango'), self::row('sierra', 'Sierra'), self::row('zulu', 'Zulu')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('sierra', 'Sierra'), self::row('zulu', 'Zulu')],
            rendered: ['key'],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Tango']));
        $context->flushToSignalRouter();

        // The window is ordered by the label and draws only the key: the drawn part is the same,
        // and the row still passed Sierra. The cut answers what the screen shows, not where the
        // row stands, so the move goes out (HIL-880, Design D6).
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_MOVED, $delta->kind);
        $this->assertSame(2, $delta->position);
    }

    public function testARowThatLeftItsSetOverAFieldNoCellDrawsStillLeaves(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike!')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike')],
            inSet: false,
            rendered: ['key'],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Mike!']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $delta->reason);
    }

    public function testARowThatKeptItsSlotAndChangedNothingDrawnSendsNothing(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Tango'), self::row('zulu', 'Zulu')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zulu')],
            rendered: ['key'],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Tango']));
        $context->flushToSignalRouter();

        // The ordered twin of the plain case: the place was judged on the whole row, the row is
        // still between Alpha and Zulu, and what is left is an update nobody would see.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAColdWindowToldWhatTheTabDrawsGoesQuietOnAFieldNoCellDraws(): void
    {
        // The cold entry: the window was served with the page, before the table was mounted, so
        // it carries no list and compares the whole row.
        $cold = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $cold->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha', false)]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha', false)], $cold);

        $context->declareTableRendered(ViewportDeltaUnitContext::PAGE, 'ak-1', $cold, ['key', 'label']);

        // The declaration is answered with nothing: the window is read again and kept, not served.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $declared = Hilos::$sr?->getTableViewport('ak-1', ViewportDeltaUnitTable::TABLE);
        $this->assertNotNull($declared);
        $this->assertSame(['key', 'label'], $declared->rendered);
        $this->assertSame(['alpha'], $declared->rowIds());

        self::replaceRows([new ViewportDeltaUnitRow('alpha', 'Alpha', true)]);
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['admin' => true]));
        $context->flushToSignalRouter();

        // The acceptance of HIL-880 on the road a freshly opened page takes: no badge over a flag
        // no cell draws.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAColdWindowToldWhatTheTabDrawsStillSendsADrawnChange(): void
    {
        $cold = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $cold->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha', false)]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha', false)], $cold);
        $context->declareTableRendered(ViewportDeltaUnitContext::PAGE, 'ak-1', $cold, ['key', 'label']);

        self::replaceRows([new ViewportDeltaUnitRow('alpha', 'Renamed', false)]);
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Renamed']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAColdWindowWhoseRowAlreadyChangedKeepsSendingThatRow(): void
    {
        $cold = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $cold->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha', false)]), 1, true, null, null);
        // The flag was set after the window went out, and its change is still on its way here.
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha', true)], $cold);

        $context->declareTableRendered(ViewportDeltaUnitContext::PAGE, 'ak-1', $cold, ['key', 'label']);
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['admin' => true]));
        $context->flushToSignalRouter();

        // The second read is not the row that was delivered, so nothing proves what the tab draws
        // of it: the row is judged whole, a delta too many rather than one too few.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
    }

    public function testAnEditThatKeepsTheRowsSlotAppliesAsAValue(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Tango'), self::row('zulu', 'Zulu')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zulu')],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Tango']));
        $context->flushToSignalRouter();

        // The value moved and the list did not: Mike became Tango and still stands between
        // Alpha and Zulu. This is the case the leaf exists for - a gate holding it raised a
        // badge whose Apply changed nothing on the screen.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        $this->assertNull($delta->position);
    }

    public function testAnEditThatChangesTheRowsSlotMovesItInsideTheWindow(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Tango'), self::row('sierra', 'Sierra'), self::row('zulu', 'Zulu')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('sierra', 'Sierra'), self::row('zulu', 'Zulu')],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Tango']));
        $context->flushToSignalRouter();

        // Alpha, Sierra, Tango, Zulu: the row passed one neighbour and lands in the third slot.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_MOVED, $delta->kind);
        $this->assertSame(2, $delta->position);
    }

    public function testAnEditPastTheTopOfAWindowHoldingTheStartMovesItToTheEdgeOfTheWindow(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Aaron'), self::row('zulu', 'Zulu')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zulu')],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Aaron']));
        $context->flushToSignalRouter();

        // The row passed the top boundary, but this window holds the start of the set: there is
        // nowhere above it to go, so it stays and takes the first slot.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_MOVED, $delta->kind);
        $this->assertSame(0, $delta->position);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAnEditPastTheBottomOfAWindowHoldingTheEndMovesItToTheEdgeOfTheWindow(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Zzz'), self::row('zulu', 'Zulu')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zulu')],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Zzz']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_MOVED, $delta->kind);
        $this->assertSame(2, $delta->position);
    }

    public function testRenamingTheTopRowOfTheFirstPageFurtherUpAppliesAsAValue(): void
    {
        $context = $this->bootOrdered(
            [self::row('bob', 'Aaron'), self::row('carol', 'Carol'), self::row('dave', 'Dave')],
            [self::row('bob', 'Bob'), self::row('carol', 'Carol'), self::row('dave', 'Dave')],
            pageIndex: 0,
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'bob', ['label' => 'Aaron']));
        $context->flushToSignalRouter();

        // Bob became Aaron and is still first: judged against its own old place it would have
        // left the window and turned into a placeholder on Apply.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        $this->assertNull($delta->position);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testTheBottomRowOfAWindowHoldingTheEndMovedFurtherDownAppliesAsAValue(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zzz')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zulu')],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'zulu', ['label' => 'Zzz']));
        $context->flushToSignalRouter();

        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $this->nextDelta()->kind);
    }

    public function testARowRemovedFromAFullAnchoredWindowDoesNotMakeItsBottomTheSetEnd(): void
    {
        $alpha = self::row('alpha', 'Alpha');
        $mike = self::row('mike', 'Mike');
        $zulu = self::row('zulu', 'Zulu');
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 3,
            sort: self::byLabel(TableConstants::ORDER_ASC),
            anchor: self::anchorAt('before-alpha'),
        );
        $viewport->recordWindow(
            self::deliveredWindow([$alpha, $mike, $zulu]),
            5,
            true,
            self::anchorOf($alpha),
            self::anchorOf($zulu),
            self::deliveredAnchors([$alpha, $mike, $zulu]),
        );
        $context = $this->bootWithViewport([$mike, self::row('zulu', 'Zzz')], $viewport);

        $context->record(SourceChange::dbDeleted(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['key' => 'alpha']));
        $context->flushToSignalRouter();
        $this->nextCount();
        $this->nextDelta();

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'zulu', ['label' => 'Zzz']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_MOVED_OUT, $delta->reason);
    }

    public function testTheTopRowOfAPageInTheMiddleOfTheSetMovedUpStillLeavesTheWindow(): void
    {
        $context = $this->bootOrdered(
            [self::row('bob', 'Aaron'), self::row('carol', 'Carol'), self::row('dave', 'Dave')],
            [self::row('bob', 'Bob'), self::row('carol', 'Carol'), self::row('dave', 'Dave')],
            pageIndex: 1,
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'bob', ['label' => 'Aaron']));
        $context->flushToSignalRouter();

        // The source reported no frame, so the window does not know what lies above it and does
        // not go on showing a row this page may no longer have (owner's decision, HIL-987).
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_MOVED_OUT, $delta->reason);
    }

    public function testAWindowOfOneRowHoldingBothEndsAppliesTheEditAsAValue(): void
    {
        $context = $this->bootOrdered([self::row('alpha', 'Zulu')], [self::row('alpha', 'Alpha')]);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Zulu']));
        $context->flushToSignalRouter();

        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $this->nextDelta()->kind);
    }

    public function testAWindowOfOneRowInTheMiddleOfTheSetMovesTheRowWithoutAPosition(): void
    {
        $context = $this->bootOrdered([self::row('alpha', 'Zulu')], [self::row('alpha', 'Alpha')], pageIndex: 1);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Zulu']));
        $context->flushToSignalRouter();

        // No neighbour to judge by, no edge of the set in hand and no frame reported by the
        // source: the window cannot say.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_MOVED, $delta->kind);
        $this->assertNull($delta->position);
    }

    public function testTheTopRowOfAFramedPageThatStaysBelowThePageAboveIsUpdatedInPlace(): void
    {
        $context = $this->bootFramed([self::row('bob', 'Ben'), self::row('carol', 'Carol'), self::row('dave', 'Dave')]);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'bob', ['label' => 'Ben']));
        $context->flushToSignalRouter();

        // 'Ben' sorts above 'Carol' but still below 'Alice', where the page above ends.
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $this->nextDelta()->kind);
    }

    public function testARowOfAFramedPageMovedAboveItsNeighboursMovesToTheTopSlot(): void
    {
        $context = $this->bootFramed([self::row('bob', 'Bob'), self::row('carol', 'Ben'), self::row('dave', 'Dave')]);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'carol', ['label' => 'Ben']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_MOVED, $delta->kind);
        $this->assertSame(0, $delta->position);
    }

    public function testTheTopRowOfAFramedPageMovedAboveThePageAboveLeavesIt(): void
    {
        $context = $this->bootFramed([self::row('bob', 'Aaron'), self::row('carol', 'Carol'), self::row('dave', 'Dave')]);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'bob', ['label' => 'Aaron']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_MOVED_OUT, $delta->reason);
    }

    public function testTheBottomRowOfAFramedPageThatStaysAboveThePageBelowIsUpdatedInPlace(): void
    {
        $context = $this->bootFramed([self::row('bob', 'Bob'), self::row('carol', 'Carol'), self::row('dave', 'Dora')]);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'dave', ['label' => 'Dora']));
        $context->flushToSignalRouter();

        // 'Dora' sorts below 'Carol' but still above 'Erin', where the page below begins.
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $this->nextDelta()->kind);
    }

    public function testTheBottomRowOfAFramedPageMovedBelowThePageBelowLeavesIt(): void
    {
        $context = $this->bootFramed([self::row('bob', 'Bob'), self::row('carol', 'Carol'), self::row('dave', 'Zed')]);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'dave', ['label' => 'Zed']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_MOVED_OUT, $delta->reason);
    }

    public function testARowLandingExactlyOnThePlaceFramingThePageLeavesIt(): void
    {
        $context = $this->bootFramed(
            [self::row('bob', 'Aaron'), self::row('carol', 'Carol'), self::row('dave', 'Dave')],
            new TableWindowFrameDTO(self::anchorOf(self::row('bob', 'Aaron')), self::anchorOf(self::row('erin', 'Erin'))),
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'bob', ['label' => 'Aaron']));
        $context->flushToSignalRouter();

        // A window taken from that place would hold the rows strictly after it, and not this one.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_MOVED_OUT, $delta->reason);
    }

    public function testAFramedWindowOfOneRowKeepsARowThatStaysWithinItsFrame(): void
    {
        $context = $this->bootFramed(
            [self::row('bob', 'Ben')],
            new TableWindowFrameDTO(self::anchorOf(self::row('alice', 'Alice')), self::anchorOf(self::row('carol', 'Carol'))),
            [self::row('bob', 'Bob')],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'bob', ['label' => 'Ben']));
        $context->flushToSignalRouter();

        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $this->nextDelta()->kind);
    }

    public function testAFramedWindowOfOneRowCannotSayWhereARowPastItsFrameWent(): void
    {
        $context = $this->bootFramed(
            [self::row('bob', 'Zed')],
            new TableWindowFrameDTO(self::anchorOf(self::row('alice', 'Alice')), self::anchorOf(self::row('carol', 'Carol'))),
            [self::row('bob', 'Bob')],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'bob', ['label' => 'Zed']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_MOVED, $delta->kind);
        $this->assertNull($delta->position);
    }

    public function testAFrameInKeysTheRowDoesNotCarryProvesNothing(): void
    {
        $context = $this->bootFramed(
            [self::row('bob', 'Ben'), self::row('carol', 'Carol'), self::row('dave', 'Dave')],
            new TableWindowFrameDTO(
                new TableAnchorDTO(['name' => 'Alice', 'id' => 'alice']),
                new TableAnchorDTO(['name' => 'Erin', 'id' => 'erin']),
            ),
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'bob', ['label' => 'Ben']));
        $context->flushToSignalRouter();

        // The table cannot compare the row with the frame, so there is no proof it stayed and the
        // answer is the one a window without a frame gives (HIL-987).
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_MOVED_OUT, $delta->reason);
    }

    public function testARowThatLeftANarrowedSetOverAnUnrenderedFieldLeavesWithTheCount(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zulu')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zulu')],
            inSet: false,
            filter: ['admin' => false],
        );

        // The rendered row is the same row: only the field the set is narrowed by moved.
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['admin' => true]));
        $context->flushToSignalRouter();

        // The count goes first and takes the row off; the delta follows it, so the window and
        // the counter agree instead of the screen holding a row the counter no longer has.
        $this->assertSame(2, $this->nextCount()->totalCount);
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $delta->reason);
    }

    public function testTheMembershipQuestionIsAskedOncePerChange(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Tango'), self::row('zulu', 'Zulu')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zulu')],
            inSet: true,
            filter: ['admin' => false],
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Tango']));
        $context->flushToSignalRouter();

        // Both the count and the classifier need the answer, and the table is asked once.
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $this->nextDelta()->kind);
        $table = Hilos::$table?->get(ViewportDeltaUnitTable::TABLE);
        $this->assertInstanceOf(ViewportDeltaUnitTable::class, $table);
        $this->assertSame(1, $table->setQuestions);
    }

    public function testAWindowWithNoOrderAppliesEveryEditAsAValue(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::deliveredWindow([self::row('alpha', 'Alpha')]), 1, true, null, null);
        $context = $this->bootWithViewport([self::row('alpha', 'Zulu')], $viewport);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Zulu']));
        $context->flushToSignalRouter();

        // No order was asked for, so the window is held in the source's own sequence and the
        // row has no place to have moved from. Five of the framework's own pages are such
        // windows, and every foreign edit on them used to raise the badge.
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $this->nextDelta()->kind);
    }

    public function testAWindowThatRememberedNoPlacesMovesTheRowWithoutOne(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            sort: self::byLabel(TableConstants::ORDER_ASC),
            limit: 10,
        );
        $viewport->recordWindow(self::deliveredWindow([self::row('alpha', 'Alpha')]), 1, true, null, null);
        $context = $this->bootWithViewport([self::row('alpha', 'Zulu')], $viewport);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Zulu']));
        $context->flushToSignalRouter();

        // Nothing here can say whether the row stayed: claiming it did would drift the window
        // away from the set, and naming a slot would put the row where nobody computed.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_MOVED, $delta->kind);
        $this->assertNull($delta->position);
    }

    public function testARowThatLeftTheFilteredSetIsRemovedWithItsOwnReason(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike!')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike')],
            inSet: false,
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Mike!']));
        $context->flushToSignalRouter();

        // Membership is asked before place: a row out of the set has no place in the window
        // to be judged by, and the reason is its own because only this one moves the count.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $delta->reason);
    }

    public function testARefusedMembershipQuestionLetsThePlaceDecide(): void
    {
        $context = $this->bootOrdered(
            [self::row('alpha', 'Alpha'), self::row('mike', 'Tango'), self::row('zulu', 'Zulu')],
            [self::row('alpha', 'Alpha'), self::row('mike', 'Mike'), self::row('zulu', 'Zulu')],
            setQuestionFails: true,
        );

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'mike', ['label' => 'Tango']));
        $context->flushToSignalRouter();

        // A table that cannot answer must not silence the delta: the classification goes on
        // by place, and the refusal is said in the log rather than in the frame.
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $this->nextDelta()->kind);
    }

    public function testOwnUnchangedRowEmitsNothing(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha')]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha')], $viewport);

        // The author is no exception: there is nothing to apply for it either.
        $context->record(SourceChange::dbUpdated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'alpha',
            ['label' => 'Alpha'],
            'ak-1',
        ));
        $context->flushToSignalRouter();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testInWindowDeleteEmitsRowRemovedDeltaAndForgetsTheRow(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, null, null);
        $context = $this->bootWithViewport([], $viewport);

        $context->record(SourceChange::dbDeleted(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['key' => 'alpha']));
        $context->flushToSignalRouter();

        $this->assertSame(0, $this->nextCount()->totalCount);

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame('alpha', $delta->rowKey);
        $this->assertSame(TableViewportDeltaDTO::REASON_DELETED, $delta->reason);
        $this->assertFalse($viewport->hasRow('alpha'));
    }

    public function testADeletedRowTheWindowDoesNotHoldIsUnannouncedBeforeTheCount(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::windowOf(['alpha']), 2, true, null, null);
        $context = $this->bootWithViewport([], $viewport);

        $context->record(SourceChange::dbDeleted(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta']));
        $context->flushToSignalRouter();

        $unannounce = $this->nextUnannounce();
        $this->assertSame(ViewportDeltaUnitContext::PAGE, $unannounce->page);
        $this->assertSame(ViewportDeltaUnitTable::TABLE, $unannounce->tableKey);
        $this->assertSame('beta', $unannounce->rowKey);

        $this->assertSame(1, $this->nextCount()->totalCount);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertTrue($viewport->hasRow('alpha'));
    }

    public function testADeletedRowTheWindowHoldsIsNotUnannounced(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, null, null);
        $context = $this->bootWithViewport([], $viewport);

        $context->record(SourceChange::dbDeleted(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['key' => 'alpha']));
        $context->flushToSignalRouter();

        $this->assertSame(0, $this->nextCount()->totalCount);
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $this->nextDelta()->kind);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testDeltaTaggedOwnWhenOriginMatchesReceiver(): void
    {
        $context = $this->boot([new ViewportDeltaUnitRow('alpha', 'Alpha')], ['alpha'], 1);
        $context->record(SourceChange::dbUpdated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'alpha',
            ['label' => 'Alpha'],
            'ak-1',
        ));
        $context->flushToSignalRouter();

        $this->assertTrue($this->nextDelta()->own);
    }

    public function testDeltaNotOwnWhenOriginIsAnotherConnection(): void
    {
        $context = $this->boot([new ViewportDeltaUnitRow('alpha', 'Alpha')], ['alpha'], 1);
        $context->record(SourceChange::dbUpdated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'alpha',
            ['label' => 'Alpha'],
            'ak-2',
        ));
        $context->flushToSignalRouter();

        $this->assertFalse($this->nextDelta()->own);
    }

    public function testDeltaNotOwnForUnattendedWrite(): void
    {
        // An agent write with no accept key set (origin null) is nobody's own; the
        // agent-origin setter (ExecutionContext::withOrigin) would supply the
        // initiator to flip this to own.
        $context = $this->boot([new ViewportDeltaUnitRow('alpha', 'Alpha')], ['alpha'], 1);
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        $this->assertFalse($this->nextDelta()->own);
    }

    public function testDeltaOwnForAWriterAnsweringTheReceiversAsk(): void
    {
        // The flip named above, as the receipt of an ask performs it (HIL-1001): the writer runs
        // where no connection is served, and the asker travels in the frame. The change is built
        // the way a row write builds it - off the execution context in force.
        $context = $this->boot([new ViewportDeltaUnitRow('alpha', 'Alpha')], ['alpha'], 1);
        ExecutionContext::withOrigin('ak-1', 'req-1', static function () use ($context): void {
            $context->record(SourceChange::dbUpdated(
                ViewportDeltaUnitTable::SOURCE_KEY,
                'alpha',
                ['label' => 'Alpha'],
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
            ));
        });
        $context->flushToSignalRouter();

        $this->assertTrue($this->nextDelta()->own);
    }

    public function testMergedSameRowRaceUsesLaterWriterOrigin(): void
    {
        $context = $this->boot([new ViewportDeltaUnitRow('alpha', 'Alpha')], ['alpha'], 1);
        // Two writes to the same row in one tick: another connection first, then this one.
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha'], 'ak-2'));
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha'], 'ak-1'));
        $context->flushToSignalRouter();

        // The later writer wins the merge, so its author (this receiver) gets own.
        $this->assertTrue($this->nextDelta()->own);
    }

    public function testOwnRemovalTagsDeltaOwn(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, null, null);
        $context = $this->bootWithViewport([], $viewport);

        $context->record(SourceChange::dbDeleted(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'alpha',
            ['key' => 'alpha'],
            'ak-1',
        ));
        $context->flushToSignalRouter();

        $this->nextCount();
        $this->assertTrue($this->nextDelta()->own);
    }

    public function testLastPageWithRoomCreateAppends(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_ASC),
        );
        // The row the window ends on is what the arriving one is placed against: 'beta' sorts
        // after 'alpha', and the window has room and holds the end of the set.
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, self::anchorAt('alpha'), self::anchorAt('alpha'));
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        $append = $this->nextAppend();
        $this->assertSame(2, $append->totalCount);
        $this->assertSame(1, $append->pageCount);
        $this->assertSame(
            [
                PagePayload::rowKey => 'beta',
                PagePayload::slots => [
                    ViewportDeltaUnitTable::SLOT => ['key' => 'beta', 'label' => 'Beta'],
                ],
            ],
            $append->row,
        );
        $this->assertTrue($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testOwnCreateArrivesPlacedWhereTheSortPutsIt(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 10,
            sort: TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_ASC)),
        );
        $viewport->recordWindow(self::windowOf(['alpha', 'gamma']), 2, true, null, null);
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('alpha', 'Alpha'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
            ],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'beta',
            ['key' => 'beta', 'label' => 'Beta'],
            'ak-1',
            'req-1',
        ));
        $context->flushToSignalRouter();

        $ownCreate = $this->nextOwnCreate();
        $this->assertSame(1, $ownCreate->position);
        $this->assertSame(3, $ownCreate->totalCount);
        $this->assertSame(1, $ownCreate->pageCount);
        $this->assertSame('req-1', $ownCreate->requestId);
        $this->assertSame(
            [
                PagePayload::rowKey => 'beta',
                PagePayload::slots => [
                    ViewportDeltaUnitTable::SLOT => ['key' => 'beta', 'label' => 'Beta'],
                ],
            ],
            $ownCreate->row,
        );
        $this->assertTrue($viewport->hasRow('beta'));
        // One road only: the tail append never runs for the author.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testOwnCreateWithNoActionBehindItCarriesNoRequestId(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, null, null);
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'beta',
            ['key' => 'beta', 'label' => 'Beta'],
            'ak-1',
        ));
        $context->flushToSignalRouter();

        $this->assertNull($this->nextOwnCreate()->requestId);
    }

    public function testNeighborOnTheSameCreateKeepsTheGeneralRules(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_ASC),
        );
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, self::anchorAt('alpha'), self::anchorAt('alpha'));
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        // Someone else's tab created the row; this window is the last page with room.
        $context->record(SourceChange::dbCreated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'beta',
            ['key' => 'beta', 'label' => 'Beta'],
            'ak-2',
            'req-2',
        ));
        $context->flushToSignalRouter();

        $this->assertSame(2, $this->nextAppend()->totalCount);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testARowSortedAboveADescendingWindowIsAnnounced(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_DESC),
        );
        $viewport->recordWindow(self::windowOf(['gamma', 'beta']), 2, true, self::anchorAt('gamma'), self::anchorAt('beta'));
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('zeta', 'Zeta'),
            ],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zeta', ['key' => 'zeta', 'label' => 'Zeta']));
        $context->flushToSignalRouter();

        // The table this leaf was written for: newest first, so the new row's place is the top
        // of the first page. The window has room at its tail, and that is not where it belongs -
        // so the row is not put in, and the window is told it exists.
        $announce = $this->nextAnnounce();
        $this->assertSame(TableRowPlacement::Above, $announce->placement);
        $this->assertSame('zeta', $announce->rowKey);
        $this->assertSame(3, $announce->totalCount);
        $this->assertTrue($announce->totalExact);
        $this->assertSame(1, $announce->pageCount);
        $this->assertFalse($viewport->hasRow('zeta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testARowBelowAFullWindowThatHoldsTheEndIsOnlyCounted(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 2,
            sort: self::byKey(TableConstants::ORDER_ASC),
            pageIndex: 0,
        );
        $viewport->recordWindow(self::windowOf(['alpha', 'gamma']), 2, true, self::anchorAt('alpha'), self::anchorAt('gamma'));
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('alpha', 'Alpha'),
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
                new ViewportDeltaUnitRow('zeta', 'Zeta'),
            ],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zeta', ['key' => 'zeta', 'label' => 'Zeta']));
        $context->flushToSignalRouter();

        // The place is right, but there is no slot to put it in: arriving here would push the
        // last row of the window onto the next page.
        $this->assertSame(3, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('zeta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testARowBelowAWindowThatDoesNotHoldTheEndIsOnlyCounted(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 3,
            sort: self::byKey(TableConstants::ORDER_ASC),
            pageIndex: 0,
        );
        $viewport->recordWindow(self::windowOf(['alpha']), 5, true, self::anchorAt('alpha'), self::anchorAt('alpha'));
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('zeta', 'Zeta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zeta', ['key' => 'zeta', 'label' => 'Zeta']));
        $context->flushToSignalRouter();

        // Room is not enough: four rows of the set are on later pages, so the row that sorts
        // below this window belongs to one of them and not to the empty slot at its tail.
        $this->assertSame(6, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('zeta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAFramedPagePastTheCountCeilingWithNothingAfterItTakesACreatedRowAtItsTail(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 3,
            sort: self::byKey(TableConstants::ORDER_ASC),
            pageIndex: 200,
        );
        // Past the ceiling the page number says nothing about where the set ends; the frame does.
        $viewport->recordWindow(
            self::windowOf(['yankee']),
            TableConstants::COUNT_CEILING,
            false,
            self::anchorAt('yankee'),
            self::anchorAt('yankee'),
            frame: new TableWindowFrameDTO(self::anchorAt('xray'), null),
        );
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('yankee', 'Yankee'), new ViewportDeltaUnitRow('zulu', 'Zulu')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zulu', ['key' => 'zulu', 'label' => 'Zulu']));
        $context->flushToSignalRouter();

        $this->assertSame('zulu', $this->nextAppend()->row[PagePayload::rowKey]);
        $this->assertTrue($viewport->hasRow('zulu'));
    }

    public function testAFramedWindowWithAPlaceAfterItOnlyCountsARowCreatedBelowIt(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_ASC),
        );
        // The build came back short, which read alone says the set ends here; the frame, read
        // first, says a row stands right after it.
        $viewport->recordWindow(
            self::windowOf(['alpha']),
            5,
            true,
            self::anchorAt('alpha'),
            self::anchorAt('alpha'),
            frame: new TableWindowFrameDTO(null, self::anchorAt('beta')),
        );
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('zeta', 'Zeta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zeta', ['key' => 'zeta', 'label' => 'Zeta']));
        $context->flushToSignalRouter();

        $this->assertSame(6, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('zeta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testARowRemovedFromAFullAnchoredWindowDoesNotMakeRoomForALowerCreate(): void
    {
        $alpha = self::row('alpha', 'Alpha');
        $mike = self::row('mike', 'Mike');
        $zulu = self::row('zulu', 'Zulu');
        $zeta = self::row('zeta', 'Zzz');
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 3,
            sort: self::byLabel(TableConstants::ORDER_ASC),
            anchor: self::anchorAt('before-alpha'),
        );
        $viewport->recordWindow(
            self::deliveredWindow([$alpha, $mike, $zulu]),
            5,
            true,
            self::anchorOf($alpha),
            self::anchorOf($zulu),
            self::deliveredAnchors([$alpha, $mike, $zulu]),
        );
        $context = $this->bootWithViewport([$mike, $zulu, $zeta], $viewport);

        $context->record(SourceChange::dbDeleted(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['key' => 'alpha']));
        $context->flushToSignalRouter();
        $this->nextCount();
        $this->nextDelta();

        $context->record(SourceChange::dbCreated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'zeta',
            ['key' => 'zeta', 'label' => 'Zzz'],
        ));
        $context->flushToSignalRouter();

        $this->assertSame(5, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('zeta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testARowBetweenTheBoundariesIsAnnounced(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_ASC),
        );
        $viewport->recordWindow(self::windowOf(['alpha', 'gamma']), 2, true, self::anchorAt('alpha'), self::anchorAt('gamma'));
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('alpha', 'Alpha'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
            ],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        // Inserting it would move every row under it down one line, which is the one thing a
        // window standing under someone's eyes does not do on its own. Saying nothing is no
        // better: the window would go on showing a set it no longer matches.
        $announce = $this->nextAnnounce();
        $this->assertSame(TableRowPlacement::Inside, $announce->placement);
        $this->assertSame('beta', $announce->rowKey);
        $this->assertSame(3, $announce->totalCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAWindowWithNoOrderTakesNoRowOfItsOwn(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, self::anchorAt('alpha'), self::anchorAt('alpha'));
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        // Such a window runs in the source's own sequence, so where the row belongs cannot be
        // read from its values - and a tail it was never shown to sit at is a guess.
        $this->assertSame(2, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAWindowWhoseTableWillNotAnswerTakesNoRowOfItsOwn(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            filter: ['status' => 'failed'],
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_ASC),
        );
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, self::anchorAt('alpha'), self::anchorAt('alpha'));
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        // A filter the table resolves itself, and a table that cannot say whether the row is in
        // this set at all: the place in the order does not answer that, so the window keeps the
        // count the whole-set re-query gives it and nothing more.
        $this->assertSame(2, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAFilteredWindowIsAnnouncedARowThatJoinedItsSet(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            filter: ['status' => 'failed'],
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_DESC),
        );
        $viewport->recordWindow(self::windowOf(['gamma', 'beta']), 2, true, self::anchorAt('gamma'), self::anchorAt('beta'));
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('zeta', 'Zeta'),
            ],
            $viewport,
            inSet: true,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zeta', ['key' => 'zeta', 'label' => 'Zeta']));
        $context->flushToSignalRouter();

        // The source says the row is in this window's set, so the window's own boundaries place
        // it as they would anywhere: above the first page, where putting it in would shift
        // every row shown and saying nothing would leave the window drifting from its set.
        $announce = $this->nextAnnounce();
        $this->assertSame(TableRowPlacement::Above, $announce->placement);
        $this->assertSame('zeta', $announce->rowKey);
        $this->assertSame(3, $announce->totalCount);
        $this->assertFalse($viewport->hasRow('zeta'));
        $this->assertTrue($viewport->hasRow('gamma'));
        $this->assertTrue($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAFilteredWindowIsToldNothingAboutARowOutsideItsSet(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            filter: ['status' => 'failed'],
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_DESC),
        );
        $viewport->recordWindow(self::windowOf(['gamma', 'beta']), 2, true, self::anchorAt('gamma'), self::anchorAt('beta'));
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('zeta', 'Zeta'),
            ],
            $viewport,
            inSet: false,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zeta', ['key' => 'zeta', 'label' => 'Zeta']));
        $context->flushToSignalRouter();

        // The row sorts above the window, but it is not in the set the window shows: there is
        // no place to announce and no row to count, so the window hears nothing at all.
        $this->assertFalse($viewport->hasRow('zeta'));
        $this->assertSame(2, $viewport->totalCount());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAFilteredWindowTakesARowThatBelongsAtItsTail(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            filter: ['status' => 'failed'],
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_ASC),
        );
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, self::anchorAt('alpha'), self::anchorAt('alpha'));
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
            inSet: true,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        // Once the row is known to be in the set, every place the classifier reads is open to
        // this window, the free slot at its tail included: the row arrives there on its own.
        $append = $this->nextAppend();
        $this->assertSame(2, $append->totalCount);
        $this->assertSame(1, $append->pageCount);
        $this->assertTrue($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAFilteredWindowAsksTheSetOncePerCreate(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            filter: ['status' => 'failed'],
            limit: 2,
            sort: self::byKey(TableConstants::ORDER_ASC),
            pageIndex: 0,
        );
        $viewport->recordWindow(self::windowOf(['alpha', 'gamma']), 2, true, self::anchorAt('alpha'), self::anchorAt('gamma'));
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('alpha', 'Alpha'),
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
                new ViewportDeltaUnitRow('zeta', 'Zeta'),
            ],
            $viewport,
            inSet: true,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zeta', ['key' => 'zeta', 'label' => 'Zeta']));
        $context->flushToSignalRouter();

        // The row falls below a full window, so both the classifier and the count need to know
        // whether it is in the set - and the table is asked once, the count reading the answer
        // the classifier already took.
        $this->assertSame(3, $this->nextCount()->totalCount);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $table = Hilos::$table?->get(ViewportDeltaUnitTable::TABLE);
        $this->assertInstanceOf(ViewportDeltaUnitTable::class, $table);
        $this->assertSame(1, $table->setQuestions);
    }

    public function testTheFirstRowOfASetArrivesInAnEmptyWindow(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 10,
            sort: self::byKey(TableConstants::ORDER_ASC),
        );
        $viewport->recordWindow([], 0, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha')], $viewport);

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['key' => 'alpha', 'label' => 'Alpha']));
        $context->flushToSignalRouter();

        // An empty window shifts nothing whatever the order says, so the boundaries it has none
        // of are not needed to decide this one.
        $append = $this->nextAppend();
        $this->assertSame(1, $append->totalCount);
        $this->assertTrue($viewport->hasRow('alpha'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAnEmptyWindowReachedBackwardsTakesNoRowOfItsOwn(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            sort: self::byKey(TableConstants::ORDER_ASC),
            limit: 10,
            anchor: self::anchorAt('alpha'),
            anchorDirection: TableAnchorDirection::Before,
        );
        $viewport->recordWindow([], 0, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('beta', 'Beta')], $viewport);

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        // The client asked for the rows before its anchor and got none, every one of them having
        // been deleted by then. Such a window is empty in the middle of the set, not at the end
        // of it, so the new row can lie anywhere - and a window that cannot say where does not
        // take the row at all.
        $this->assertSame(1, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testTheAuthorOfARowOnAnotherPageIsToldAboutIt(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            sort: self::byKey(TableConstants::ORDER_ASC),
            limit: 2,
            pageIndex: 1,
        );
        $viewport->recordWindow(self::windowOf(['gamma', 'zeta']), 3, true, self::anchorAt('gamma'), self::anchorAt('zeta'));
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('alpha', 'Alpha'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
                new ViewportDeltaUnitRow('zeta', 'Zeta'),
            ],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'beta',
            ['key' => 'beta', 'label' => 'Beta'],
            'ak-1',
            'req-1',
        ));
        $context->flushToSignalRouter();

        // The author's own road ends where its row lands on a page it is not looking at. The ban
        // on a second road was there to keep a row from being sent twice and to keep a row out of
        // a filter that excludes it; an announcement sends no row, and a filtered window is
        // announced to only once the source says the row is in its set, so the author is told
        // the same thing everyone else is.
        $announce = $this->nextAnnounce();
        $this->assertSame(TableRowPlacement::Above, $announce->placement);
        $this->assertSame('beta', $announce->rowKey);
        $this->assertSame(4, $announce->totalCount);
        $this->assertSame(2, $announce->pageCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAWindowWhoseCountStoppedAtItsCeilingIsStillAnnouncedTo(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            sort: self::byKey(TableConstants::ORDER_DESC),
            limit: 10,
        );
        $viewport->recordWindow(
            self::windowOf(['gamma', 'beta']),
            TableConstants::COUNT_CEILING,
            true,
            self::anchorAt('gamma'),
            self::anchorAt('beta'),
        );
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('zeta', 'Zeta'),
            ],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zeta', ['key' => 'zeta', 'label' => 'Zeta']));
        $context->flushToSignalRouter();

        // The count path goes silent past the ceiling because one more row makes "at least 500"
        // no truer. This one is not about the number: a row the window is not showing exists
        // whatever the pager can say, so the word goes out with the ceiling and no page count.
        $announce = $this->nextAnnounce();
        $this->assertSame(TableRowPlacement::Above, $announce->placement);
        $this->assertSame(TableConstants::COUNT_CEILING, $announce->totalCount);
        $this->assertFalse($announce->totalExact);
        $this->assertNull($announce->pageCount);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testTheAuthorOfARowOnAnotherPageOfAFilteredSetIsToldAboutIt(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            filter: ['status' => 'failed'],
            sort: self::byKey(TableConstants::ORDER_ASC),
            limit: 2,
            pageIndex: 1,
        );
        $viewport->recordWindow(self::windowOf(['gamma', 'zeta']), 3, true, self::anchorAt('gamma'), self::anchorAt('zeta'));
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('alpha', 'Alpha'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
                new ViewportDeltaUnitRow('zeta', 'Zeta'),
            ],
            $viewport,
            inSet: true,
        );

        $context->record(SourceChange::dbCreated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'beta',
            ['key' => 'beta', 'label' => 'Beta'],
            'ak-1',
            'req-1',
        ));
        $context->flushToSignalRouter();

        // The author's row is in the set it filtered, only on the page before the one it is
        // looking at: its own road finds no slot, and the general one tells it what it tells
        // every other window of that set.
        $announce = $this->nextAnnounce();
        $this->assertSame(TableRowPlacement::Above, $announce->placement);
        $this->assertSame('beta', $announce->rowKey);
        $this->assertSame(4, $announce->totalCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAFilteredWindowWithAnInexactCountIsStillAnnouncedTo(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            filter: ['status' => 'failed'],
            sort: self::byKey(TableConstants::ORDER_DESC),
            limit: 10,
        );
        $viewport->recordWindow(
            self::windowOf(['gamma', 'beta']),
            TableConstants::COUNT_CEILING,
            false,
            self::anchorAt('gamma'),
            self::anchorAt('beta'),
        );
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('zeta', 'Zeta'),
            ],
            $viewport,
            inSet: true,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'zeta', ['key' => 'zeta', 'label' => 'Zeta']));
        $context->flushToSignalRouter();

        // The count path of such a window leaves before it ever asks the set, so until now it
        // heard nothing on a create. The classifier asks for itself, and the word goes out with
        // the ceiling and no page count.
        $announce = $this->nextAnnounce();
        $this->assertSame(TableRowPlacement::Above, $announce->placement);
        $this->assertSame(TableConstants::COUNT_CEILING, $announce->totalCount);
        $this->assertFalse($announce->totalExact);
        $this->assertNull($announce->pageCount);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAnAnnouncedRowIsNotOneTheWindowRemembers(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            sort: self::byKey(TableConstants::ORDER_ASC),
            limit: 10,
        );
        $viewport->recordWindow(self::windowOf(['alpha', 'gamma']), 2, true, self::anchorAt('alpha'), self::anchorAt('gamma'));
        $context = $this->bootWithViewport(
            [
                new ViewportDeltaUnitRow('alpha', 'Alpha'),
                new ViewportDeltaUnitRow('beta', 'Beta'),
                new ViewportDeltaUnitRow('gamma', 'Gamma'),
            ],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();
        $this->assertSame(TableRowPlacement::Inside, $this->nextAnnounce()->placement);

        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta II']));
        $context->flushToSignalRouter();

        // Announcing a row puts nothing into the window: it holds what the connection has been
        // shown, and this row was not. So the next edit of it is an edit of a row this window
        // never had, and nothing follows from it.
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testOwnCreateOutsideTheAuthorSearchShowsNothing(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            limit: 10,
            filter: [TableConstants::FILTER_KEY_SEARCH => 'alpha'],
        );
        $viewport->recordWindow(self::windowOf(['alpha']), 1, true, null, null);
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'beta',
            ['key' => 'beta', 'label' => 'Beta'],
            'ak-1',
            'req-1',
        ));
        $context->flushToSignalRouter();

        // The row is not in this window's set at all, so neither it nor the count moves.
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testOwnCreateOnAFurtherPageLeavesOnlyTheCount(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 1);
        $viewport->recordWindow(self::windowOf(['alpha']), 5, true, null, null);
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(
            ViewportDeltaUnitTable::SOURCE_KEY,
            'beta',
            ['key' => 'beta', 'label' => 'Beta'],
            'ak-1',
            'req-1',
        ));
        $context->flushToSignalRouter();

        $this->assertSame(6, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testCreateOffTheLastPageEmitsCount(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 1);
        $viewport->recordWindow(self::windowOf(['alpha']), 5, true, null, null);
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        $this->assertSame(6, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testNoViewportDropsTheChange(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new ViewportDeltaUnitTableContext([new ViewportDeltaUnitRow('alpha', 'Alpha')]);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            ViewportDeltaUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', ViewportDeltaUnitContext::PAGE),
        );

        $context = new ViewportDeltaUnitContext();
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        // A viewport table with no active viewport delivers nothing — the change is
        // dropped, and the next table_viewport request returns the current window.
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testWorkReportedBySourceReachesTheWindowWithoutTouchingIt(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha')]), 1, true, null, null);
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha')],
            $viewport,
            reportsProgress: true,
        );

        $context->record(SourceChange::rtUpdated(ViewportDeltaUnitTable::PROGRESS_SOURCE_KEY, 'beta', ['step' => 3]));
        $context->flushToSignalRouter();

        $progress = $this->nextProgress();
        $this->assertSame(ViewportDeltaUnitTable::PROGRESS_KEY, $progress->progress->progressKey);
        $this->assertSame('beta', $progress->progress->rowKey);
        $this->assertSame(3, $progress->progress->current);

        // A bar is not in the count and is not a row of the window: the total stands where it
        // stood, nothing else was queued, and the key the bar names is one the window does not
        // hold. That is what keeps it out of Apply, out of the selection and out of the pager.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertSame(1, $viewport->totalCount());
        $this->assertFalse($viewport->hasRow('beta'));
    }

    public function testATableThatDeclaresNoWorkIsAskedForNoneAndSendsNothing(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::deliveredWindow([new ViewportDeltaUnitRow('alpha', 'Alpha')]), 1, true, null, null);
        $context = $this->bootWithViewport([new ViewportDeltaUnitRow('alpha', 'Alpha')], $viewport);

        // The same change against the framework default: this table never overrode the
        // declaration, so the fan-out branch ends at the first of the two questions.
        $context->record(SourceChange::rtUpdated(ViewportDeltaUnitTable::PROGRESS_SOURCE_KEY, 'beta', ['step' => 3]));
        $context->flushToSignalRouter();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * Turns a list of row-id keys into a window of placeholder wire rows.
     *
     * A placeholder equals no row the fixture table builds, so a window recorded
     * this way still expects every delta it expected before rows were compared;
     * a test about suppression records the real row body instead.
     *
     * @param list<string> $rowIds Row-id keys the connection holds, in display order
     * @return array<string, array{rowKey: int|string, slots: array<string, mixed>}> Window of placeholder rows
     */
    private static function windowOf(array $rowIds): array
    {
        $window = [];
        foreach ($rowIds as $rowId) {
            $window[$rowId] = [PagePayload::rowKey => $rowId, PagePayload::slots => []];
        }

        return $window;
    }

    /**
     * Builds the one-column order the windows of these tests are held in.
     *
     * @param string $direction Direction the order runs in
     * @return TableSortOrderDTO Order over the row key
     */
    private static function byKey(string $direction): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO('key', $direction));
    }

    /**
     * Builds the place a boundary row of such a window sits at.
     *
     * @param string $rowKey Row key of the boundary row
     * @return TableAnchorDTO Place that row sits at in the window's order
     */
    private static function anchorAt(string $rowKey): TableAnchorDTO
    {
        return new TableAnchorDTO(['key' => $rowKey]);
    }

    /**
     * Builds the window the server records after delivering these rows for real.
     *
     * Mirrors the pair the delivery path runs a row through
     * ({@see ViewportDeltaUnitTable::browserRow()} and the wire mapping behind it),
     * so a window recorded this way holds what the connection has actually seen.
     *
     * @param list<ViewportDeltaUnitRow> $rows Rows delivered to the connection, in display order
     * @return array<string, array{rowKey: int|string, slots: array<string, mixed>}> Window of delivered rows
     */
    private static function deliveredWindow(array $rows): array
    {
        $window = [];
        foreach ($rows as $row) {
            $window[$row->getRowKey()] = [
                PagePayload::rowKey => $row->getRowKey(),
                PagePayload::slots => [ViewportDeltaUnitTable::SLOT => $row->toArray()],
            ];
        }

        return $window;
    }

    /**
     * Builds one row of these fixtures.
     *
     * @param string $key Row key
     * @param string $label Label the ordered windows of these tests are held by
     * @return ViewportDeltaUnitRow Fixture row
     */
    private static function row(string $key, string $label): ViewportDeltaUnitRow
    {
        return new ViewportDeltaUnitRow($key, $label);
    }

    /**
     * Builds the one-column order over the field an edit can actually move.
     *
     * The windows sorted by row key cannot move a row at all: an update never rewrites the key
     * a row is addressed by, so a place read off it is the place the row already had.
     *
     * @param string $direction Direction the order runs in
     * @return TableSortOrderDTO Order over the row label
     */
    private static function byLabel(string $direction): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO('label', $direction));
    }

    /**
     * Builds the place one row of such a window stands at, in the order over the label.
     *
     * @param ViewportDeltaUnitRow $row Row standing in the window
     * @return TableAnchorDTO Place that row stands at
     */
    private static function anchorOf(ViewportDeltaUnitRow $row): TableAnchorDTO
    {
        return new TableAnchorDTO(['label' => $row->label, 'key' => $row->key]);
    }

    /**
     * Builds the places a window remembers for the rows it delivered, in display order.
     *
     * @param list<ViewportDeltaUnitRow> $rows Rows delivered to the connection, in display order
     * @return array<string, ?TableAnchorDTO> Place of each delivered row, keyed by row-id key
     */
    private static function deliveredAnchors(array $rows): array
    {
        $anchors = [];
        foreach ($rows as $row) {
            $anchors[$row->key] = self::anchorOf($row);
        }

        return $anchors;
    }

    /**
     * Replaces the rows the fixture table owns, as a write to its source would, keeping the router and its windows.
     *
     * @param list<ViewportDeltaUnitRow> $rows Snapshot rows the table owns from now on
     */
    private static function replaceRows(array $rows): void
    {
        Hilos::$table = new ViewportDeltaUnitTableContext($rows);
        Hilos::$table->configure();
    }

    /**
     * Boots a connection holding an ordered window it remembers the places of.
     *
     * @param list<ViewportDeltaUnitRow> $rows Table rows the fixture owns, as they are AFTER the edit
     * @param list<ViewportDeltaUnitRow> $windowRows Rows the connection was delivered, in display order
     * @param ?bool $inSet What the table answers about a row's membership, or null when it cannot say
     * @param bool $setQuestionFails Whether the membership question refuses instead of answering
     * @param array<string, mixed> $filter Open filter map narrowing the window's set, search included
     * @param ?int $pageIndex Numbered page the window jumped to, or null when it was asked from the start of the set
     * @param list<string> $rendered Fields the tab declared it draws, empty when it declared none
     * @return ViewportDeltaUnitContext Booted browser context
     */
    private function bootOrdered(
        array $rows,
        array $windowRows,
        ?bool $inSet = null,
        bool $setQuestionFails = false,
        array $filter = [],
        ?int $pageIndex = null,
        array $rendered = [],
    ): ViewportDeltaUnitContext {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            filter: $filter,
            sort: self::byLabel(TableConstants::ORDER_ASC),
            limit: 10,
            pageIndex: $pageIndex,
            rendered: $rendered,
        );
        $viewport->recordWindow(
            self::deliveredWindow($windowRows),
            count($windowRows),
            true,
            self::anchorOf($windowRows[0]),
            self::anchorOf($windowRows[count($windowRows) - 1]),
            self::deliveredAnchors($windowRows),
        );

        return $this->bootWithViewport($rows, $viewport, $inSet, $setQuestionFails);
    }

    /**
     * Boots a connection holding page one of a set ordered by label, framed by the places its source reported.
     *
     * The page above ends on 'Alice' and the page below begins with 'Erin' unless the frame says
     * otherwise; the set holds nine rows, so the page stands in the middle of it.
     *
     * @param list<ViewportDeltaUnitRow> $rows Table rows the fixture owns, as they are AFTER the edit
     * @param ?TableWindowFrameDTO $frame Places framing the page, or null for 'Alice' above and 'Erin' below
     * @param list<ViewportDeltaUnitRow> $windowRows Rows the connection was delivered, or empty for Bob, Carol and Dave
     * @return ViewportDeltaUnitContext Booted browser context
     */
    private function bootFramed(array $rows, ?TableWindowFrameDTO $frame = null, array $windowRows = []): ViewportDeltaUnitContext
    {
        if ($windowRows === []) {
            $windowRows = [self::row('bob', 'Bob'), self::row('carol', 'Carol'), self::row('dave', 'Dave')];
        }
        $viewport = new TableViewportSubscription(
            tableKey: ViewportDeltaUnitTable::TABLE,
            sort: self::byLabel(TableConstants::ORDER_ASC),
            limit: count($windowRows),
            pageIndex: 1,
        );
        $viewport->recordWindow(
            self::deliveredWindow($windowRows),
            9,
            true,
            self::anchorOf($windowRows[0]),
            self::anchorOf($windowRows[count($windowRows) - 1]),
            self::deliveredAnchors($windowRows),
            $frame ?? new TableWindowFrameDTO(self::anchorOf(self::row('alice', 'Alice')), self::anchorOf(self::row('erin', 'Erin'))),
        );

        return $this->bootWithViewport($rows, $viewport);
    }

    /**
     * Boots the registry, table, page subscription, and a recorded viewport.
     *
     * @param list<ViewportDeltaUnitRow> $rows Table rows the fixture owns
     * @param list<string> $windowRowIds Row-id keys recorded as the connection's window
     * @param int $totalCount Total count recorded for the window
     * @return ViewportDeltaUnitContext Booted browser context
     */
    private function boot(array $rows, array $windowRowIds, int $totalCount): ViewportDeltaUnitContext
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::windowOf($windowRowIds), $totalCount, true, null, null);

        return $this->bootWithViewport($rows, $viewport);
    }

    /**
     * Boots the registry, table, page subscription, and the given viewport.
     *
     * @param list<ViewportDeltaUnitRow> $rows Table rows the fixture owns
     * @param TableViewportSubscription $viewport Viewport to register for the connection
     * @param ?bool $inSet What the table answers about a row's membership, or null when it cannot say
     * @param bool $setQuestionFails Whether the membership question refuses instead of answering
     * @param bool $reportsProgress Whether the table reads work out of a source change at all
     * @return ViewportDeltaUnitContext Booted browser context
     */
    private function bootWithViewport(
        array $rows,
        TableViewportSubscription $viewport,
        ?bool $inSet = null,
        bool $setQuestionFails = false,
        bool $reportsProgress = false,
    ): ViewportDeltaUnitContext {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new ViewportDeltaUnitTableContext($rows, $inSet, $setQuestionFails, $reportsProgress);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            ViewportDeltaUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', ViewportDeltaUnitContext::PAGE),
        );
        Hilos::$sr->setTableViewport('ak-1', $viewport);

        return new ViewportDeltaUnitContext();
    }

    /**
     * Asserts the next queued signal is an addressed table viewport delta and returns it.
     *
     * @return TableViewportDeltaDTO The delta payload
     */
    private function nextDelta(): TableViewportDeltaDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportDeltaDTO::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Asserts the next queued signal is an addressed table viewport count and returns it.
     *
     * @return TableViewportCountDTO The count payload
     */
    private function nextCount(): TableViewportCountDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_COUNT, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportCountDTO::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Asserts the next queued signal is an addressed table viewport append and returns it.
     *
     * @return TableViewportAppendDTO The append payload
     */
    private function nextAppend(): TableViewportAppendDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_APPEND, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportAppendDTO::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Asserts the next queued signal is an addressed table viewport announcement and returns it.
     *
     * @return TableViewportAnnounceDTO The announce payload
     */
    private function nextAnnounce(): TableViewportAnnounceDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_ANNOUNCE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportAnnounceDTO::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Asserts the next queued signal is an addressed table viewport unannouncement and returns it.
     *
     * @return TableViewportUnannounceDTO The unannounce payload
     */
    private function nextUnannounce(): TableViewportUnannounceDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_UNANNOUNCE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportUnannounceDTO::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Asserts the next queued signal is an addressed table progress frame and returns it.
     *
     * @return TableProgressSignalData The progress payload
     */
    private function nextProgress(): TableProgressSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_PROGRESS, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableProgressSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Asserts the next queued signal is an addressed table viewport own create and returns it.
     *
     * @return TableViewportOwnCreateDTO The own-create payload
     */
    private function nextOwnCreate(): TableViewportOwnCreateDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_OWN_CREATE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportOwnCreateDTO::class, $signal->data->data);

        return $signal->data->data;
    }
}

final class ViewportDeltaUnitContext extends BrowserContext
{
    public const string PAGE = 'viewport_delta_page';
    public const string SIGNAL = 'viewport_delta_signal';

    /**
     * Resolves the test page browser metadata.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
        ]);
    }

    /**
     * Binds the test page to the self-snapshot table.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([
            ViewportDeltaUnitTable::TABLE => [],
        ]);
    }
}

final class ViewportDeltaUnitTableContext extends TableContext
{
    /**
     * @param list<ViewportDeltaUnitRow> $rows Snapshot rows the table owns
     * @param ?bool $inSet What the table answers about a row's membership, or null when it cannot say
     * @param bool $setQuestionFails Whether the membership question refuses instead of answering
     * @param bool $reportsProgress Whether the table reads work out of a source change at all
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?bool $inSet = null,
        private readonly bool $setQuestionFails = false,
        private readonly bool $reportsProgress = false,
    ) {
    }

    public function configure(): void
    {
        $this->register(
            ViewportDeltaUnitTable::TABLE,
            new ViewportDeltaUnitTable($this->rows, $this->inSet, $this->setQuestionFails, $this->reportsProgress),
        );
    }
}

final class ViewportDeltaUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'viewportDeltaTable';
    public const string SLOT = 'viewportDeltaRows';
    public const string SOURCE_KEY = 'viewportDeltaSource';
    public const string PROGRESS_SOURCE_KEY = 'viewportDeltaProgressSource';
    public const string PROGRESS_KEY = 'viewportDeltaRun';

    /** How many times the membership question has been put to this table. */
    public int $setQuestions = 0;

    /**
     * @param list<ViewportDeltaUnitRow> $rows Snapshot rows the table owns
     * @param ?bool $inSet What this table answers about a row's membership, or null when it cannot say
     * @param bool $setQuestionFails Whether the membership question refuses instead of answering
     * @param bool $reportsProgress Whether this table reads work out of a source change at all
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?bool $inSet = null,
        private readonly bool $setQuestionFails = false,
        private readonly bool $reportsProgress = false,
    ) {
        parent::__construct();
    }

    /**
     * Reads work out of a source change of its own, or leaves the answer to the framework.
     *
     * The two are the fixture's whole point: a table that declares work reports it from a source
     * the row path knows nothing of, and a table that declares none takes the default and is
     * asked for nothing.
     *
     * @param SourceChange $change Source change that may report work on this table
     * @return ?TableProgressDTO Bar for the run source, or whatever the default answers
     * @throws InvalidArgumentException When the bar is built with a row key its place refuses
     */
    public function buildProgressForSourceEvent(SourceChange $change): ?TableProgressDTO
    {
        if (!$this->reportsProgress || $change->sourceKey !== self::PROGRESS_SOURCE_KEY) {
            return parent::buildProgressForSourceEvent($change);
        }

        return new TableProgressDTO(
            TableProgressScope::Row,
            self::PROGRESS_KEY,
            (string) $change->sourceId,
            3,
            11,
        );
    }

    /**
     * Answers whether one row belongs to the set, from what the fixture was told to say.
     *
     * @param string|int $rowKey Row key to place against the set (ignored: the fixture states the answer)
     * @param TableQueryDTO $query Window query whose search and filters describe the set
     * @return ?bool Whether the row is in the set, or null when this table cannot answer
     * @throws HilosException When the fixture was told to refuse the question
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        $this->setQuestions++;
        if ($this->setQuestionFails) {
            throw new HilosException('the fixture refuses the set question');
        }

        return $this->inSet;
    }

    /**
     * Maps a source change to a row mutation preserving its type when the row
     * exists, or a delete otherwise.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation, or null for another source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== self::SOURCE_KEY) {
            return null;
        }

        foreach ($this->rows as $row) {
            if ($row->getRowKey() === $change->sourceId) {
                return $this->mutation($change->mutationType, $row->getRowKey(), $row);
            }
        }

        return $this->mutation(TableMutationType::Delete, $change->sourceId);
    }

    /**
     * Serializes a row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Self-snapshot row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Configures the row class so makeRows rebuilds typed rows from the filter output.
     */
    protected function init(): void
    {
        $this->setRowClass(ViewportDeltaUnitRow::class);
    }

    /**
     * Applies the in-memory filter to the injected rows.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = array_map(static fn(ViewportDeltaUnitRow $row): array => $row->toArray(), $this->rows);

        return $this->filterInMemory($rows, $query);
    }
}

final class ViewportDeltaUnitRow extends AbstractTableRow
{
    /**
     * @param string $key Row key
     * @param string $label Label the row is shown and ordered by
     * @param ?bool $admin Flag the row carries and no cell draws, or null for a row that does not carry it
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?bool $admin = null,
    ) {
    }

    public function getRowKey(): string
    {
        return $this->key;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return 'key';
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        $fields = [
            'key' => $this->key,
            'label' => $this->label,
        ];
        if ($this->admin !== null) {
            $fields['admin'] = $this->admin;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (string) $data['key'],
            (string) $data['label'],
            $data['admin'] ?? null,
        );
    }
}
