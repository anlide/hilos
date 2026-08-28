<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\ProtectedModeClearedMailTemplate;
use Hilos\Mail\Template\ProtectedModeStuckMailTemplate;
use Hilos\ProtectedMode\ProtectedModeStuckVerdict;
use Hilos\ProtectedMode\ProtectedModeWatchdog;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * What the watchdog says about a freeze that stopped moving (HIL-482).
 *
 * The subject of every case here is the report, because reporting is all this class does: it never
 * lifts a freeze, never moves a phase and never touches the row. So the assertions read the log the
 * master would have written, and what they pin is which of the four verdicts it names, that a
 * healthy freeze is left alone however long it runs, and that one stuck freeze produces one line
 * rather than one per iteration of the daemon loop.
 *
 * The clock is passed to every tick, so the thresholds are exercised without waiting them out, and
 * the row is mounted directly from a row array rather than driven through the executor: a freeze
 * that began an hour ago is the ordinary subject here, and the actions stamp only the present.
 */
final class ProtectedModeWatchdogTest extends TestCase
{
    /** @var int Silence threshold these cases configure, in seconds */
    private const int SILENCE_TIMEOUT = 600;

    /** @var int Quiesce threshold these cases configure, in seconds */
    private const int QUIESCE_TIMEOUT = 30;

    /** @var int Reminder interval these cases configure, in seconds */
    private const int ALERT_INTERVAL = 900;

    /** @var int Epoch second every freeze in these cases began at */
    private const int STARTED_AT = 1_700_000_000;

    /** @var string Agent type recorded as the initiator */
    private const string INITIATOR_TYPE = 'hilos_backup';

    /** @var int Agent index recorded as the initiator */
    private const int INITIATOR_INDEX = 2;

    /** @var string Agent id the initiator's stop arrives under */
    private const string INITIATOR_ID = 'hilos_backup:2';

    /** @var string Operation the freeze protects */
    private const string OPERATION = 'backup:restore';

    /** Temporary main log file the assertions read the written report back from */
    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    private ?HilosMailer $previousMailer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-protected-mode-watchdog');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousMailer = Hilos::$mail;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::HILOS_PROTECTED_MODE_SILENCE_TIMEOUT->name . '=' . self::SILENCE_TIMEOUT);
        putenv(EnvConstants::HILOS_PROTECTED_MODE_QUIESCE_TIMEOUT->name . '=' . self::QUIESCE_TIMEOUT);
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_INTERVAL->name . '=' . self::ALERT_INTERVAL);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        putenv(EnvConstants::HILOS_PROTECTED_MODE_SILENCE_TIMEOUT->name);
        putenv(EnvConstants::HILOS_PROTECTED_MODE_QUIESCE_TIMEOUT->name);
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_INTERVAL->name);
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name);
        putenv(EnvConstants::MAIL_WORKER_COUNT->name);
        Hilos::$env = $this->previousEnv;
        Hilos::$mail = $this->previousMailer;
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testAFreezeStillInsideItsThresholdIsLeftAlone(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();

        $watchdog->tick(self::STARTED_AT);
        $watchdog->tick(self::STARTED_AT + self::SILENCE_TIMEOUT);

        $this->assertSame('', $this->written());
    }

    public function testAFreezeThatWentSilentIsReported(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();

        // Ticked from the moment the freeze appears, as the daemon loop ticks it, so the silence
        // is measured over the whole life of the freeze and not from the first look at it.
        $watchdog->tick(self::STARTED_AT);
        $watchdog->tick(self::STARTED_AT + self::SILENCE_TIMEOUT + 1);

        $this->assertVerdict(ProtectedModeStuckVerdict::SILENT);
        $this->assertStringContainsString(self::OPERATION, $this->written());
    }

    public function testAProgressMarkKeepsAnOldFreezeOutOfTheReport(): void
    {
        // The point of the whole mark: a restore that has been running for an hour is not stuck,
        // and its length must not be what decides. What decides is when it last said anything.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();
        $watchdog->tick(self::STARTED_AT);

        $this->stampProgress(self::STARTED_AT + 3600);
        $watchdog->tick(self::STARTED_AT + 3600 + self::SILENCE_TIMEOUT);

        $this->assertSame('', $this->written());
    }

    public function testAFreezeInheritedMidFlightGetsAFullWindowRatherThanAnInstantReport(): void
    {
        // What a leader promoted during a long restore reads: a row whose clocks are hours old,
        // because the progress marks were going to the row of the leader before it. Reporting on
        // that would make every leader change during a healthy operation raise a false alarm.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();
        $inheritedAt = self::STARTED_AT + 100_000;

        $watchdog->tick($inheritedAt);
        $watchdog->tick($inheritedAt + self::SILENCE_TIMEOUT);
        $this->assertSame('', $this->written());

        // And it is still watched: silence from the moment it was taken over is reported.
        $watchdog->tick($inheritedAt + self::SILENCE_TIMEOUT + 1);

        $this->assertVerdict(ProtectedModeStuckVerdict::SILENT);
    }

    public function testOneStuckFreezeIsReportedOnceAndNotOnEveryTick(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();
        $stuckAt = self::STARTED_AT + self::SILENCE_TIMEOUT + 1;
        $watchdog->tick(self::STARTED_AT);

        $watchdog->tick($stuckAt);
        $watchdog->tick($stuckAt + 1);
        $watchdog->tick($stuckAt + 2);

        $this->assertSame(1, substr_count($this->written(), 'is stuck:'));
    }

    public function testAQuiesceRoundThatNeverClosesIsReported(): void
    {
        // A freeze stuck half-entered: nothing destructive has run, and it is still only reported.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVATING, activatedAt: null);
        $watchdog = new ProtectedModeWatchdog();

        // The round is counted from when this process took it over, never from the row's own
        // start: a leader that inherits a freeze would otherwise declare it overdue at once.
        $watchdog->tick(self::STARTED_AT + 10_000);
        $this->assertSame('', $this->written());

        $watchdog->tick(self::STARTED_AT + 10_000 + self::QUIESCE_TIMEOUT + 1);

        $this->assertVerdict(ProtectedModeStuckVerdict::QUIESCE_OVERDUE);
    }

    public function testAStoppedInitiatorIsReportedWithoutWaitingOutAnyThreshold(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();

        $watchdog->onAgentStopped(self::INITIATOR_ID);
        $watchdog->tick(self::STARTED_AT + 1);

        $this->assertVerdict(ProtectedModeStuckVerdict::INITIATOR_LOST);
    }

    public function testTheInitiatorLossSurvivesThatAgentStartingAgain(): void
    {
        // The agent-start gate lets the initiator's type start again under the standing freeze, and
        // a fresh instance marks progress. That must not read as the operation coming back: the
        // operation died with the process that was running it.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();

        $watchdog->onAgentStopped(self::INITIATOR_ID);
        $this->stampProgress(self::STARTED_AT + 5);
        $watchdog->tick(self::STARTED_AT + 6);

        $this->assertVerdict(ProtectedModeStuckVerdict::INITIATOR_LOST);
    }

    public function testTheStopOfAnyOtherAgentIsNotTheInitiatorLeaving(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();

        $watchdog->onAgentStopped('chat');
        $watchdog->tick(self::STARTED_AT + 1);

        $this->assertSame('', $this->written());
    }

    public function testAFreezeRestoredFromDiskIsReportedOnTheFirstTick(): void
    {
        // A node that came back holding a freeze knows it started no operation behind it, so there
        // is nothing to wait for: whatever was running died with the daemon.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();

        $watchdog->markRestoredFromDisk();
        $watchdog->tick(self::STARTED_AT + 1);

        $this->assertVerdict(ProtectedModeStuckVerdict::RESTORED_FROM_DISK);
    }

    public function testALostInitiatorOutranksTheSilenceItAlsoCauses(): void
    {
        // Both hold at once here, and the stronger statement is the one the operator gets: an
        // initiator known to be gone is a fact, while silence is only an inference from a clock.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();
        $stuckAt = self::STARTED_AT + self::SILENCE_TIMEOUT + 1;
        $watchdog->tick(self::STARTED_AT);

        $watchdog->tick($stuckAt);
        $this->assertVerdict(ProtectedModeStuckVerdict::SILENT);

        $watchdog->onAgentStopped(self::INITIATOR_ID);
        $watchdog->tick($stuckAt + 1);

        $this->assertVerdict(ProtectedModeStuckVerdict::INITIATOR_LOST);
    }

    public function testAnInactiveNodeIsWatchedInCompleteSilence(): void
    {
        Hilos::$rt = new WatchdogTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());

        new ProtectedModeWatchdog()->tick(self::STARTED_AT + 100_000);

        $this->assertSame('', $this->written());
    }

    public function testAProcessWithoutARuntimeRowWatchesNothingInsteadOfFailing(): void
    {
        Hilos::$rt = null;

        new ProtectedModeWatchdog()->tick(self::STARTED_AT);

        $this->assertSame('', $this->written());
    }

    public function testTheNextFreezeIsJudgedOnItsOwnAfterTheFirstIsLifted(): void
    {
        // Everything remembered is dropped when the phase reaches inactive, the stopped initiator
        // included - otherwise the next operation would be reported stuck the moment it started.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();
        $watchdog->onAgentStopped(self::INITIATOR_ID);
        $watchdog->tick(self::STARTED_AT + 1);
        $this->assertVerdict(ProtectedModeStuckVerdict::INITIATOR_LOST);

        $this->lift();
        $watchdog->tick(self::STARTED_AT + 2);

        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT + 3, startedAt: self::STARTED_AT + 3);
        file_put_contents($this->logFile, '');
        $watchdog->tick(self::STARTED_AT + 4);

        $this->assertSame('', $this->written());
    }

    public function testTheAlertRepeatsOnTheIntervalAndNotBefore(): void
    {
        // A stuck freeze is a condition, not an event: the operator who was asleep, or whose mail
        // bounced, is the normal case, so the alarm says the same thing again while it holds.
        $mailer = $this->mailTo('ops@example.com');
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();
        $stuckAt = self::STARTED_AT + self::SILENCE_TIMEOUT + 1;

        $watchdog->tick(self::STARTED_AT);
        $watchdog->tick($stuckAt);
        $this->assertCount(1, $mailer->sent);
        $this->assertFalse($mailer->sent[0]['params'][ProtectedModeStuckMailTemplate::PARAM_STILL]);

        $watchdog->tick($stuckAt + self::ALERT_INTERVAL - 1);
        $this->assertCount(1, $mailer->sent);

        $watchdog->tick($stuckAt + self::ALERT_INTERVAL);

        $this->assertCount(2, $mailer->sent);
        $this->assertTrue($mailer->sent[1]['params'][ProtectedModeStuckMailTemplate::PARAM_STILL]);
        $this->assertSame(
            MailTemplateCatalogConstants::PROTECTED_MODE_STUCK,
            $mailer->sent[1]['templateKey'],
        );
    }

    public function testTheAllClearIsSentOnlyForAFreezeThatWasReported(): void
    {
        $mailer = $this->mailTo('ops@example.com');
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();
        $stuckAt = self::STARTED_AT + self::SILENCE_TIMEOUT + 1;
        $watchdog->tick(self::STARTED_AT);
        $watchdog->tick($stuckAt);

        $this->lift();
        $watchdog->tick($stuckAt + 60);

        $this->assertCount(2, $mailer->sent);
        $cleared = $mailer->sent[1];
        $this->assertSame(MailTemplateCatalogConstants::PROTECTED_MODE_CLEARED, $cleared['templateKey']);
        // Read off what the watchdog kept: the release clears the operation and both clocks before
        // this runs, so a freeze that is over can no longer describe itself.
        $this->assertSame(
            self::OPERATION,
            $cleared['params'][ProtectedModeClearedMailTemplate::PARAM_OPERATION],
        );
        $this->assertSame('11 minutes', $cleared['params'][ProtectedModeClearedMailTemplate::PARAM_HELD_FOR]);
        $this->assertSame('1 minute', $cleared['params'][ProtectedModeClearedMailTemplate::PARAM_STUCK_FOR]);
    }

    public function testAFreezeThatWorriedNobodyIsLiftedInSilence(): void
    {
        $mailer = $this->mailTo('ops@example.com');
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT);
        $watchdog = new ProtectedModeWatchdog();
        $watchdog->tick(self::STARTED_AT);

        $this->lift();
        $watchdog->tick(self::STARTED_AT + 60);

        $this->assertSame([], $mailer->sent);
        $this->assertSame('', $this->written());
    }

    /**
     * Points the mail facade at a recorder and configures who the alert goes to.
     *
     * @param string $address Recipient the alert is configured for
     * @return WatchdogRecordingMailer Recorder the case reads its assertions from
     */
    private function mailTo(string $address): WatchdogRecordingMailer
    {
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name . '=' . $address);
        putenv(EnvConstants::MAIL_WORKER_COUNT->name . '=1');
        $mailer = new WatchdogRecordingMailer();
        Hilos::$mail = $mailer;

        return $mailer;
    }

    /**
     * Mounts a freeze row with the timestamps a case needs, as a node under a freeze carries it.
     *
     * @param string $phase Phase the freeze stands on
     * @param ?int $activatedAt Epoch seconds the freeze settled, or null while it is still activating
     * @param ?int $progressAt Epoch seconds of the last progress mark, or null when none was left
     * @param int $startedAt Epoch seconds the freeze began
     */
    private function freeze(
        string $phase,
        ?int $activatedAt,
        ?int $progressAt = null,
        int $startedAt = self::STARTED_AT,
    ): void {
        Hilos::$rt = new WatchdogTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::operation => self::OPERATION,
            StateProtectedModeRuntime::initiatorAgentType => self::INITIATOR_TYPE,
            StateProtectedModeRuntime::initiatorAgentIndex => self::INITIATOR_INDEX,
            StateProtectedModeRuntime::initiatorNodeId => 'node-a',
            StateProtectedModeRuntime::startedAt => $startedAt,
            StateProtectedModeRuntime::activatedAt => $activatedAt,
            StateProtectedModeRuntime::progressAt => $progressAt,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedAcceptKeys => [],
        ]));
    }

    /**
     * Puts a progress mark on the freeze, as a live operation's report leaves one.
     *
     * Re-mounted rather than written through the actions: the row keeps its operation and its
     * start, which is what the watchdog fingerprints, so this is the same freeze with one more
     * thing said about it.
     *
     * @param int $at Epoch seconds of the mark
     */
    private function stampProgress(int $at): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, activatedAt: self::STARTED_AT, progressAt: $at);
    }

    /**
     * Lifts the freeze, leaving the node carrying the inactive row a release leaves behind.
     */
    private function lift(): void
    {
        Hilos::$rt = new WatchdogTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
    }

    /**
     * Asserts the log carries the report of exactly this verdict.
     *
     * The verdict is read off the wording rather than off a getter: the report IS the class's
     * output, and a test that asked the object what it thought would pass over a watchdog that
     * decided correctly and told nobody.
     *
     * @param ProtectedModeStuckVerdict $verdict Verdict the report must carry
     */
    private function assertVerdict(ProtectedModeStuckVerdict $verdict): void
    {
        $written = $this->written();
        $this->assertStringContainsString('is stuck:', $written);
        $this->assertStringContainsString(self::problemOf($verdict), $written);
    }

    /**
     * The clause the report carries for one verdict.
     *
     * @param ProtectedModeStuckVerdict $verdict Verdict to word
     * @return string Fragment of the report unique to that verdict
     */
    private static function problemOf(ProtectedModeStuckVerdict $verdict): string
    {
        return match ($verdict) {
            ProtectedModeStuckVerdict::INITIATOR_LOST => 'the initiator stopped ',
            ProtectedModeStuckVerdict::RESTORED_FROM_DISK => 'restored from disk',
            ProtectedModeStuckVerdict::QUIESCE_OVERDUE => 'never confirmed the freeze',
            ProtectedModeStuckVerdict::SILENT => 'has reported no progress for',
        };
    }

    /**
     * @return string Everything written to the log so far
     */
    private function written(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Project context that registers no runtime state of its own: the framework mount supplies the
 * freeze row.
 *
 * Named apart from its neighbours on purpose - the protected-mode test files share one namespace,
 * so one context name could not carry two classes and the suite would not load.
 */
final class WatchdogTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/**
 * A mailer that records what would have been queued instead of queueing it.
 *
 * Its own class rather than a share of the notifier test's recorder: PHPUnit includes a test file
 * when it runs it, so a helper borrowed across files works only while the order happens to favour
 * it, and these cases would fail for a reason that has nothing to do with the watchdog.
 */
final class WatchdogRecordingMailer extends HilosMailer
{
    /** @var list<array{to: string, templateKey: ?string, params: array<string, mixed>}> Captured sends */
    public array $sent = [];

    /**
     * @param EmailMessage|MailSendSignalData $message Message the notifier handed over
     */
    public function send(EmailMessage|MailSendSignalData $message): void
    {
        if (!$message instanceof MailSendSignalData) {
            return;
        }

        $this->sent[] = [
            'to' => $message->to,
            'templateKey' => $message->templateKey,
            'params' => $message->params,
        ];
    }
}
