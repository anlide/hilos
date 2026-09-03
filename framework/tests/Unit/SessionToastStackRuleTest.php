<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\View\Collection\HilosSessionToastStacks;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rule that takes a session's toast off every tab at once (HIL-768).
 *
 * The whole leaf comes down to one sentence - a card goes when a person closed it, or when a
 * LIVE socket's countdown has finished and no LIVE socket is reading - and that sentence has
 * four outcomes worth pinning, because each of them is a way two windows could disagree:
 * a countdown that finished while a neighbour is reading, the same one once the reader leaves,
 * a holder that closed its tab without ever letting go, and a person clicking the cross.
 *
 * The fifth thing pinned is the row's own life: an empty stack is not stored, so the
 * collection is the size of what is on screen rather than of the sessions that have ever been
 * shown something.
 *
 * They are asked of the actions directly rather than through the agent, because this is where
 * the rule lives; what the agent adds - which sockets are alive, and telling the tabs - is
 * pinned by the integration test beside it.
 */
final class SessionToastStackRuleTest extends TestCase
{
    private const string SESSION_HASH = '4e1243bd22c66e76c2ba9eddc1f91394e57f9f83';

    private const string TAB_A = 'accept-key-a';

    private const string TAB_B = 'accept-key-b';

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousRt = Hilos::$rt;
        Hilos::$rt = new SessionToastTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        // Both of these are done by facade init() in a running process, and the rule cannot be
        // watched without them: a store that never learned its name announces nothing, and
        // without the subscriber the view keeps answering with the wrapper of a row that left.
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        // The sessions library claims the collection the same way at agent start; without a
        // truth source every write below would be refused as coming from nowhere.
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
    }

    public function testACardStaysWhileItsCountdownFinishedInOneTabAndAnotherIsReading(): void
    {
        $stacks = $this->stacks();
        $this->raise('Backup "2026-09-03" is ready.');
        $stacks->actions->setReading(self::SESSION_HASH, self::TAB_B, true);
        $stacks->actions->markExpired(self::SESSION_HASH, $this->onlyKey(), self::TAB_A);

        $changed = $stacks->actions->settle(self::SESSION_HASH, [self::TAB_A, self::TAB_B]);

        // The point of the leaf: nothing vanishes from under a reading cursor, even though a
        // countdown somewhere else has honestly finished.
        $this->assertFalse($changed);
        $this->assertCount(1, $stacks[self::SESSION_HASH]?->toasts ?? []);
    }

    public function testTheCardGoesTheMomentTheLastReaderLetsGo(): void
    {
        $stacks = $this->stacks();
        $this->raise('Backup "2026-09-03" is ready.');
        $stacks->actions->setReading(self::SESSION_HASH, self::TAB_B, true);
        $stacks->actions->markExpired(self::SESSION_HASH, $this->onlyKey(), self::TAB_A);
        $stacks->actions->settle(self::SESSION_HASH, [self::TAB_A, self::TAB_B]);

        $stacks->actions->setReading(self::SESSION_HASH, self::TAB_B, false);
        $changed = $stacks->actions->settle(self::SESSION_HASH, [self::TAB_A, self::TAB_B]);

        // The waiting expiry fires at once rather than starting the neighbour's countdown
        // again: one rule instead of two, and it is what the ticket asks for in words.
        $this->assertTrue($changed);
        $this->assertNull($stacks[self::SESSION_HASH]);
    }

    public function testAHolderThatClosedItsTabStopsVetoing(): void
    {
        $stacks = $this->stacks();
        $this->raise('Backup "2026-09-03" is ready.');
        $stacks->actions->setReading(self::SESSION_HASH, self::TAB_B, true);
        $stacks->actions->markExpired(self::SESSION_HASH, $this->onlyKey(), self::TAB_A);

        // Tab B is gone and said nothing on its way out - the case only the tick can close.
        $changed = $stacks->actions->settle(self::SESSION_HASH, [self::TAB_A]);

        $this->assertTrue($changed);
        $this->assertNull($stacks[self::SESSION_HASH]);
    }

    public function testACountdownReportedByATabThatIsGoneDoesNotExpireTheCard(): void
    {
        $stacks = $this->stacks();
        $this->raise('Backup "2026-09-03" is ready.');
        $stacks->actions->markExpired(self::SESSION_HASH, $this->onlyKey(), self::TAB_B);

        // Tab B counted down and then closed. Its report must not answer for tab A, which has
        // only just been shown the card.
        $changed = $stacks->actions->settle(self::SESSION_HASH, [self::TAB_A]);

        $this->assertFalse($changed);
        $this->assertCount(1, $stacks[self::SESSION_HASH]?->toasts ?? []);
    }

    public function testClosingTheCardTakesItAwayWithoutAskingAnybody(): void
    {
        $stacks = $this->stacks();
        $this->raise('Backup "2026-09-03" is ready.');
        $stacks->actions->setReading(self::SESSION_HASH, self::TAB_A, true);

        $changed = $stacks->actions->dismiss(self::SESSION_HASH, $this->onlyKey());

        // Closing is an answer from the one person behind the session, so a cursor resting on
        // the stack does not hold it back the way it holds back a countdown.
        $this->assertTrue($changed);
        $this->assertNull($stacks[self::SESSION_HASH]);
    }

    public function testClosingOneOfTwoCardsLeavesTheOtherAndTheRow(): void
    {
        $stacks = $this->stacks();
        $this->raise('Backup "2026-09-03" is ready.');
        $first = $this->onlyKey();
        $this->raise('Backup "2026-09-02" is ready.');

        $stacks->actions->dismiss(self::SESSION_HASH, $first);

        $toasts = $stacks[self::SESSION_HASH]?->toasts ?? [];
        $this->assertCount(1, $toasts);
        $this->assertSame(
            'Backup "2026-09-02" is ready.',
            $toasts[0][StateHilosSessionToastStack::TOAST_MESSAGE],
        );
    }

    public function testASessionWithNoLiveSocketLosesItsRow(): void
    {
        $stacks = $this->stacks();
        $this->raise('Backup "2026-09-03" is ready.');

        $changed = $stacks->actions->settle(self::SESSION_HASH, []);

        // A toast lives only while somebody may be looking at it; the next tab to open is a
        // person arriving after the fact, not the person who was told.
        $this->assertTrue($changed);
        $this->assertNull($stacks[self::SESSION_HASH]);
    }

    public function testTheSameSentenceRaisedTwiceIsOneCardCountedTwice(): void
    {
        $stacks = $this->stacks();
        $this->raise('Backup "2026-09-03" is ready.');
        $stacks->actions->markExpired(self::SESSION_HASH, $this->onlyKey(), self::TAB_A);

        $this->raise('Backup "2026-09-03" is ready.');

        // Counted here rather than in the browser: a tab that merged two of the session's
        // cards would hold one key for a row the server thinks is two. The repeat also voids
        // the finished countdown, because every tab starts counting again when the count moves.
        $toasts = $stacks[self::SESSION_HASH]?->toasts ?? [];
        $this->assertCount(1, $toasts);
        $this->assertSame(2, $toasts[0][StateHilosSessionToastStack::TOAST_REPEATS]);
        $this->assertSame([], $toasts[0][StateHilosSessionToastStack::TOAST_EXPIRED_BY]);
    }

    /**
     * @return HilosSessionToastStacks Mounted collection under test
     */
    private function stacks(): HilosSessionToastStacks
    {
        $stacks = Hilos::$rt?->hilosSessionToastStacks;
        $this->assertInstanceOf(HilosSessionToastStacks::class, $stacks);

        return $stacks;
    }

    /**
     * @param string $message Sentence the card carries
     */
    private function raise(string $message): void
    {
        $this->stacks()->actions->raise(
            self::SESSION_HASH,
            'toast-key-' . substr(sha1($message), 0, 8),
            $message,
            SessionToastSeverity::SUCCESS,
            'Backup',
            '/hilos/backup',
        );
    }

    /**
     * @return string Key of the card most recently added to the stack
     */
    private function onlyKey(): string
    {
        $toasts = $this->stacks()[self::SESSION_HASH]?->toasts ?? [];
        $this->assertNotSame([], $toasts);

        return $toasts[count($toasts) - 1][StateHilosSessionToastStack::TOAST_KEY];
    }
}

/**
 * Bare runtime context: the toast stacks are mounted by the framework, so nothing else is
 * needed to exercise them.
 */
final class SessionToastTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
