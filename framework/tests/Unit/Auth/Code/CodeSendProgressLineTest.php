<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Code;

use Hilos\Auth\Code\CodeSendTicket;
use Hilos\Auth\Code\DTO\CodeSendProgressSignalData;
use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Library\Command\AbstractLibraryCommands;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Verification\VerificationSendOutcome;
use Hilos\Core\Exception\LogicException;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\View\Collection\HilosCodeSendAttempts;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rule that decides which reported step the line believes (HIL-826).
 *
 * The line is one row per browser session and it follows exactly one send at a time, so every
 * question worth pinning here is a question about a report arriving when the line has already
 * moved on: a resend replacing what was being watched, an attempt that died and spoke late,
 * and the mail queue's own retry, which is a refusal that must NOT read as "could not send".
 *
 * The last two cases are the row's life rather than its rule: the line is born already queued,
 * because a state-less row is one the screen cannot read, and it goes when the wait it belongs
 * to is let go.
 *
 * They are asked of the actions directly rather than through the agent, because this is where
 * the rule lives; what the agent adds - which session a step belongs to, and telling the tabs -
 * rides on the frames it publishes.
 */
final class CodeSendProgressLineTest extends TestCase
{
    private const string SESSION_HASH = '4e1243bd22c66e76c2ba9eddc1f91394e57f9f83';

    private const string OTHER_SESSION_HASH = 'b1d5781111d84f7b3fe45a0852e59758cd7a87e5';

    private const string TICKET = 'a1b2c3d4e5f60718';

    private const string OTHER_TICKET = '0f1e2d3c4b5a6978';

    private const string REFUSAL = 'mailbox unavailable (550)';

    /** A window no line in a test could be older than; stands for a live challenge. */
    private const int WHOLE_CODE_LIFETIME_MS = 60000;

    /** Long enough that the millisecond clock has certainly moved on. */
    private const int PAST_ANY_WINDOW_US = 2000;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousRt = Hilos::$rt;
        Hilos::$rt = new CodeSendProgressTestRtContext();
        // The line is mounted by the sign-in feature and by nothing else, so an empty list
        // would leave nothing here to exercise - which is itself the decision being tested.
        Hilos::$rt->mountFeatureRuntime([new AuthFeature()]);
        // Both of these are done by facade init() in a running process, and the rule cannot be
        // watched without them: a store that never learned its name announces nothing, and
        // without the subscriber the view keeps answering with the wrapper of a row that left.
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        // The sessions library claims the collection the same way at agent start; without a
        // truth source every write below would be refused as coming from nowhere.
        RtTruthSourceRegistry::registerDaemon(StateHilosCodeSendAttempt::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosCodeSendAttempt::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
    }

    public function testTheLineIsBornQueuedAndNamesItsChannel(): void
    {
        $attempts = $this->attempts();

        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);

        // A row with no state is one the screen cannot read, so the order being placed is
        // itself the first thing the line says.
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $attempts[self::SESSION_HASH]?->state);
        $this->assertSame(StateHilosCodeSendAttempt::CHANNEL_EMAIL, $attempts[self::SESSION_HASH]?->channel);
        $this->assertNull($attempts[self::SESSION_HASH]?->detail);
    }

    public function testAResendReplacesTheLineRatherThanStandingBesideIt(): void
    {
        $attempts = $this->attempts();
        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);
        $attempts->actions->advance(self::TICKET, StateHilosCodeSendAttempt::STATE_SENT, null);

        $attempts->actions->start(self::SESSION_HASH, self::OTHER_TICKET, 'telegram');

        // The screen shows one line, about the code the person is waiting for NOW.
        $this->assertCount(1, $attempts);
        $this->assertSame(self::OTHER_TICKET, $attempts[self::SESSION_HASH]?->ticket);
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $attempts[self::SESSION_HASH]?->state);
    }

    public function testAReportOfTheSendThatWasReplacedMovesNothing(): void
    {
        $attempts = $this->attempts();
        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);
        $attempts->actions->start(self::SESSION_HASH, self::OTHER_TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);

        $moved = $attempts->actions->advance(self::TICKET, StateHilosCodeSendAttempt::STATE_FAILED, self::REFUSAL);

        // The whole of the resend race, settled without comparing moments: a dying attempt
        // does not get to paint its refusal over the send that replaced it.
        $this->assertNull($moved);
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $attempts[self::SESSION_HASH]?->state);
        $this->assertNull($attempts[self::SESSION_HASH]?->detail);
    }

    public function testAnAcceptedStepNamesTheSessionItMoved(): void
    {
        $attempts = $this->attempts();
        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);

        $moved = $attempts->actions->advance(self::TICKET, StateHilosCodeSendAttempt::STATE_SENDING, null);

        // The transport reports a ticket and knows no session; the answer is what the owner
        // publishes the line to.
        $this->assertSame(self::SESSION_HASH, $moved);
        $this->assertSame(StateHilosCodeSendAttempt::STATE_SENDING, $attempts[self::SESSION_HASH]?->state);
    }

    public function testARefusalCarriesTheProvidersOwnSentence(): void
    {
        $attempts = $this->attempts();
        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);

        $attempts->actions->advance(self::TICKET, StateHilosCodeSendAttempt::STATE_FAILED, self::REFUSAL);

        // "Something went wrong" is what the ticket exists to stop being the answer.
        $this->assertSame(StateHilosCodeSendAttempt::STATE_FAILED, $attempts[self::SESSION_HASH]?->state);
        $this->assertSame(self::REFUSAL, $attempts[self::SESSION_HASH]?->detail);
    }

    public function testALetterOnlyWrittenDownEndsTheLineWithoutASentence(): void
    {
        $attempts = $this->attempts();
        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);
        $attempts->actions->advance(self::TICKET, StateHilosCodeSendAttempt::STATE_SENDING, null);

        $moved = $attempts->actions->advance(self::TICKET, StateHilosCodeSendAttempt::STATE_NOT_SENT, null);

        // Terminal like `sent` and a success like it: nothing follows, nothing is retried, and
        // there is no provider sentence to carry because nothing went wrong (HIL-827, Flow F2).
        $this->assertSame(self::SESSION_HASH, $moved);
        $this->assertSame(StateHilosCodeSendAttempt::STATE_NOT_SENT, $attempts[self::SESSION_HASH]?->state);
        $this->assertNull($attempts[self::SESSION_HASH]?->detail);
    }

    public function testGoingBackToQueuedLosesTheSentenceItWasCarrying(): void
    {
        $attempts = $this->attempts();
        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);
        $attempts->actions->advance(self::TICKET, StateHilosCodeSendAttempt::STATE_FAILED, self::REFUSAL);

        $attempts->actions->advance(self::TICKET, StateHilosCodeSendAttempt::STATE_QUEUED, null);

        // The raw-send queue retries with a backoff, so a retryable refusal is a return to the
        // queue - and yesterday's reason must not be left standing under today's state.
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $attempts[self::SESSION_HASH]?->state);
        $this->assertNull($attempts[self::SESSION_HASH]?->detail);
    }

    public function testOneSessionsLineIsInvisibleToAnother(): void
    {
        $attempts = $this->attempts();
        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);

        // The DONE WHEN clause "another browser sees nothing", read where it is decided: the
        // row is addressed by session, so there is nothing under anybody else's key.
        $this->assertNull($attempts[self::OTHER_SESSION_HASH]);
    }

    public function testDroppingTheLineSaysWhetherThereWasOne(): void
    {
        $attempts = $this->attempts();
        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);

        $this->assertTrue($attempts->actions->drop(self::SESSION_HASH));
        $this->assertNull($attempts[self::SESSION_HASH]);
        // The wait is let go in several places that run for sessions which never ordered a
        // code, so a second drop is a no-op rather than a failure - and the answer is what
        // stops an empty frame being published to nobody.
        $this->assertFalse($attempts->actions->drop(self::SESSION_HASH));
    }

    public function testALineOutLivesItsSocketsAndDiesWithItsCode(): void
    {
        $attempts = $this->attempts();
        $attempts->actions->start(self::SESSION_HASH, self::TICKET, StateHilosCodeSendAttempt::CHANNEL_EMAIL);

        // Nothing about live sockets is asked, and that is the decision: a reload drops every
        // tab of a browser for a moment, and coming back to the same line is half of what the
        // leaf is for. The toast stack settles the opposite way and is not the model here.
        $this->assertSame(0, $attempts->actions->forgetStale(self::WHOLE_CODE_LIFETIME_MS));
        $this->assertNotNull($attempts[self::SESSION_HASH]);

        usleep(self::PAST_ANY_WINDOW_US);

        // What ends it is the code going stale: past the challenge's own lifetime there is
        // nothing left to enter, so a line still saying "sent" describes nothing.
        $this->assertSame(1, $attempts->actions->forgetStale(1));
        $this->assertNull($attempts[self::SESSION_HASH]);
    }

    public function testTheOwnerOpensTheLineOnTheReportThatNamesASession(): void
    {
        $agent = new CodeSendProgressTestAgent();

        $this->report($agent, CodeSendStepSignalData::queued(
            self::TICKET,
            self::SESSION_HASH,
            StateHilosCodeSendAttempt::CHANNEL_EMAIL,
        ));

        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $this->attempts()[self::SESSION_HASH]?->state);
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $this->lastPublishedState());
    }

    public function testARetryableRefusalPutsTheLineBackInTheQueue(): void
    {
        $agent = new CodeSendProgressTestAgent();
        $this->report($agent, CodeSendStepSignalData::queued(
            self::TICKET,
            self::SESSION_HASH,
            StateHilosCodeSendAttempt::CHANNEL_EMAIL,
        ));
        $this->report($agent, CodeSendStepSignalData::step(self::TICKET, StateHilosCodeSendAttempt::STATE_SENDING));

        $this->report($agent, CodeSendStepSignalData::step(self::TICKET, StateHilosCodeSendAttempt::STATE_QUEUED));

        // The mail queue reports `queued` a SECOND time when a retryable refusal puts the
        // letter back in it, and that report names no session - only the opening one does. An
        // owner that chose its branch by the STATE would drop it here and leave the line on
        // `sending` for the rest of the wait.
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $this->attempts()[self::SESSION_HASH]?->state);
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $this->lastPublishedState());
    }

    public function testAStepOfASendTheLineHasMovedOnFromIsPublishedToNobody(): void
    {
        $agent = new CodeSendProgressTestAgent();
        $this->report($agent, CodeSendStepSignalData::queued(
            self::TICKET,
            self::SESSION_HASH,
            StateHilosCodeSendAttempt::CHANNEL_EMAIL,
        ));
        $this->drainFrames();

        $this->report($agent, CodeSendStepSignalData::step(
            self::OTHER_TICKET,
            StateHilosCodeSendAttempt::STATE_FAILED,
            self::REFUSAL,
        ));

        // Nothing moved, so nothing is said: a frame repeating what the last one said is a
        // frame nobody needed, and this one would carry a dead attempt's refusal.
        $this->assertNull($this->lastPublishedState());
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $this->attempts()[self::SESSION_HASH]?->state);
    }

    public function testTheGateRefusalIsSaidOutLoudSoTheLineStopsWaiting(): void
    {
        $commands = new CodeSendProgressTestCommands(new CodeSendProgressTestUsersAgent());

        // A cooldown hold means an earlier code went out and IS the one the screen is waiting
        // for - the phone path answers its rate-limited arm the same way.
        $commands->close(self::TICKET, VerificationSendOutcome::heldByCooldown(30));
        $this->assertSame(StateHilosCodeSendAttempt::STATE_SENT, $this->lastReportedStepState());

        // A cap refusal means nothing is travelling at all, so the line stops promising.
        $commands->close(self::TICKET, VerificationSendOutcome::capReached());
        $this->assertSame(StateHilosCodeSendAttempt::STATE_FAILED, $this->lastReportedStepState());

        // A send that really went out is left alone: the transport carrying it reports the rest,
        // and a word from here would be a second voice on one send.
        $commands->close(self::TICKET, VerificationSendOutcome::sent(30));
        $this->assertNull($this->lastReportedStepState());
    }

    public function testEverySendGetsANameOfItsOwn(): void
    {
        // Per send, not per session and not per address - which is what makes a stale report
        // recognizable at all.
        $this->assertNotSame(CodeSendTicket::mint(), CodeSendTicket::mint());
        $this->assertSame(16, strlen(CodeSendTicket::mint()));
    }

    /**
     * Hands one reported step to the owner the way the router does.
     *
     * @param CodeSendProgressTestAgent $agent Owner under test
     * @param CodeSendStepSignalData $frame The step being reported
     */
    private function report(CodeSendProgressTestAgent $agent, CodeSendStepSignalData $frame): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(data: $frame),
            'test',
            HilosSignalConstants::HILOS_CODE_SEND_STEP,
        );
    }

    /**
     * Drains the queue and answers the state of the last step REPORTED to the owner.
     *
     * @return ?string State the last reported step carried, or null when none was reported
     */
    private function lastReportedStepState(): ?string
    {
        $state = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $signal->data;
            if (!$payload instanceof AgentSignalData || !$payload->data instanceof CodeSendStepSignalData) {
                continue;
            }

            $state = $payload->data->state;
        }

        return $state;
    }

    /**
     * Drains the queue and answers the state of the last line published to a browser.
     *
     * @return ?string State the last published frame carried, or null when none was published
     */
    private function lastPublishedState(): ?string
    {
        $state = null;
        foreach ($this->drainFrames() as $frame) {
            $state = $frame->state;
        }

        return $state;
    }

    /**
     * @return list<CodeSendProgressSignalData> Progress frames queued since the last drain
     */
    private function drainFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $signal->data;
            if (!$payload instanceof WebSocketSignalData || !$payload->data instanceof CodeSendProgressSignalData) {
                continue;
            }

            $frames[] = $payload->data;
        }

        return $frames;
    }

    /**
     * @return HilosCodeSendAttempts Mounted collection under test
     */
    private function attempts(): HilosCodeSendAttempts
    {
        $attempts = Hilos::$rt?->hilosCodeSendAttempts;
        $this->assertInstanceOf(HilosCodeSendAttempts::class, $attempts);

        return $attempts;
    }
}

/**
 * Bare runtime context: the line arrives with the sign-in feature, so nothing else is needed
 * to exercise it.
 */
final class CodeSendProgressTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/**
 * Sessions library of a fixture project, which needs nothing beyond being instantiable.
 */
final class CodeSendProgressTestAgent extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Users library of a fixture project, built only to carry a commands group's frames.
 *
 * Its three project seams are never reached: what is under test is one report, and a report
 * is queued through the agent rather than asked of the project.
 */
final class CodeSendProgressTestUsersAgent extends AbstractUsersLibraryAgent
{
    public function createUser(string $displayName): int
    {
        throw new LogicException('The fixture library makes no users');
    }

    public function displayNameOf(int $userId): ?string
    {
        throw new LogicException('The fixture library names no users');
    }

    protected function buildAuthMethods(): IdentifierDetector
    {
        throw new LogicException('The fixture library detects nothing');
    }
}

/**
 * Commands group that exposes the one report under test; it holds no commands of its own.
 */
final class CodeSendProgressTestCommands extends AbstractLibraryCommands
{
    /**
     * @param string $ticket Ticket the line is following
     * @param VerificationSendOutcome $outcome What the send gate answered
     */
    public function close(string $ticket, VerificationSendOutcome $outcome): void
    {
        $this->closeRefusedCodeSendLine($ticket, $outcome);
    }
}
