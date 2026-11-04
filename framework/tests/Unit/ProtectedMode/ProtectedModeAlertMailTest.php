<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\Log\AgentLogStream;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\MailTemplateRegistry;
use Hilos\Mail\Template\ProtectedModeClearedMailTemplate;
use Hilos\Mail\Template\ProtectedModeSlaveAlertMailTemplate;
use Hilos\Mail\Template\ProtectedModeStuckMailTemplate;
use Hilos\ProtectedMode\ProtectedModeAlertNotifier;
use Hilos\ProtectedMode\ProtectedModeWatchdog;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The message an operator actually receives about a frozen node (HIL-482).
 *
 * The wording is the subject here, not an implementation detail of it: this mail is read on a phone
 * at 3am by someone who has to decide whether to look at the node first or open it, so the copy was
 * approved sentence by sentence and these cases hold it to that. What they check is that the body
 * says the node is serving nobody, that nothing will fix it by itself, and which two commands end
 * it - and that a reminder differs from the first alert only in its subject line.
 *
 * The delivery half is checked on the same terms: one message per configured address, and an empty
 * list saying so in the log rather than throwing, because a node that never needed a watchdog must
 * not be made unstartable by a channel it never configured.
 */
final class ProtectedModeAlertMailTest extends TestCase
{
    private const string NODE = 'hilos-node-1';

    /** Temporary main log file the assertions read written lines back from */
    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    private ?HilosMailer $previousMailer = null;

    private ?RtContext $previousRuntime = null;

    /** Isolates the environment, mail and runtime facades for each recipient check. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-protected-mode-alert');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousMailer = Hilos::$mail;
        $this->previousRuntime = Hilos::$rt;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::MAIL_WORKER_COUNT->name . '=1');
    }

    /** Restores the facades and removes the log streams owned by this case. */
    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $agentLog = AgentLogStream::pathFor(dirname($this->logFile), ProtectedModeAlertNotifier::LOG_AGENT_ID, true);
        if (is_file($agentLog)) {
            unlink($agentLog);
        }

        putenv(EnvConstants::MAIL_WORKER_COUNT->name);
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name);
        Hilos::$env = $this->previousEnv;
        Hilos::$mail = $this->previousMailer;
        Hilos::$rt = $this->previousRuntime;

        parent::tearDown();
    }

    public function testTheFirstAlertRendersTheApprovedBody(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::PROTECTED_MODE_STUCK,
            self::stuckParams(still: false),
            null,
        );

        self::assertSame('Node Worker (hilos-node-1) is stuck in maintenance', $content->subject);
        self::assertStringContainsString(
            'The node Worker (hilos-node-1) has been frozen for maintenance since 16 Aug 2026 03:12:44 UTC (32 minutes)'
            . ' and it will not come back on its own.',
            $content->text,
        );
        self::assertStringContainsString('Operation: backup:restore', $content->text);
        self::assertStringContainsString('Phase:     active', $content->text);
        self::assertStringContainsString('Initiator: hilos_backup agent on hilos-node-1', $content->text);
        self::assertStringContainsString('Problem:   the initiator has reported no progress for 14 minutes', $content->text);
        self::assertStringContainsString('While the freeze holds, nobody is served by this node.', $content->text);
        self::assertStringContainsString(
            'It will not be lifted' . "\n" . 'automatically: the data here may be half-written',
            $content->text,
        );
        self::assertStringContainsString('php cli.php protected-mode:pass', $content->text);
        self::assertStringContainsString('php cli.php protected-mode:open', $content->text);
        self::assertStringContainsString('This message repeats every 15 minutes until the freeze is lifted.', $content->text);
    }

    public function testTheReminderChangesOnlyTheSubject(): void
    {
        // A reminder that reworded its body would read as a second incident rather than as the same
        // one still running, which is the opposite of what repeating is for.
        $registry = new MailTemplateRegistry();

        $first = $registry->render(
            MailTemplateCatalogConstants::PROTECTED_MODE_STUCK,
            self::stuckParams(still: false),
            null,
        );
        $reminder = $registry->render(
            MailTemplateCatalogConstants::PROTECTED_MODE_STUCK,
            [...self::stuckParams(still: true), ProtectedModeStuckMailTemplate::PARAM_FROZEN_FOR => '1h 02m'],
            null,
        );

        self::assertSame('Node Worker (hilos-node-1) is still stuck in maintenance (1h 02m)', $reminder->subject);
        self::assertNotSame($first->subject, $reminder->subject);
        self::assertSame(
            str_replace('32 minutes', '1h 02m', $first->text),
            $reminder->text,
        );
    }

    public function testTheAllClearRendersTheApprovedBody(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::PROTECTED_MODE_CLEARED,
            [
                ProtectedModeClearedMailTemplate::PARAM_NODE_ID => self::NODE,
                ProtectedModeClearedMailTemplate::PARAM_NODE_NAME => 'Worker',
                ProtectedModeClearedMailTemplate::PARAM_OPERATION => 'backup:restore',
                ProtectedModeClearedMailTemplate::PARAM_LIFTED_AT => '16 Aug 2026 04:20:10 UTC',
                ProtectedModeClearedMailTemplate::PARAM_HELD_FOR => '1h 07m',
                ProtectedModeClearedMailTemplate::PARAM_STUCK_FOR => '55 minutes',
            ],
            null,
        );

        self::assertSame('Node Worker (hilos-node-1) is out of maintenance', $content->subject);
        self::assertStringContainsString(
            'The freeze on Worker (hilos-node-1) was lifted at 16 Aug 2026 04:20:10 UTC. It had held for 1h 07m,'
            . ' 55 minutes of them with nothing running behind it.',
            $content->text,
        );
        self::assertStringContainsString('Lifted by: protected-mode:open', $content->text);
        self::assertStringContainsString('No further alerts will be sent for this freeze.', $content->text);
    }

    public function testBothTemplatesFallBackToTheIdWhenTheNameIsUnknown(): void
    {
        $stuck = self::stuckParams(still: false);
        $stuck[ProtectedModeStuckMailTemplate::PARAM_NODE_NAME] = null;
        $content = new ProtectedModeStuckMailTemplate()->render($stuck, null);
        self::assertSame('Node hilos-node-1 is stuck in maintenance', $content->subject);
        self::assertStringContainsString('The node hilos-node-1 has been frozen', $content->text);
        $cleared = new ProtectedModeClearedMailTemplate()->render([
            ProtectedModeClearedMailTemplate::PARAM_NODE_ID => self::NODE,
            ProtectedModeClearedMailTemplate::PARAM_NODE_NAME => null,
        ], null);
        self::assertSame('Node hilos-node-1 is out of maintenance', $cleared->subject);
        self::assertStringContainsString('The freeze on hilos-node-1 was lifted', $cleared->text);
    }

    public function testEveryConfiguredAddressGetsItsOwnMessage(): void
    {
        // One message per address and not one with several recipients: the mail pool shards by
        // address, so each lands on the agent that owns it and one unreachable mailbox cannot hold
        // up the others.
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name . '=ops@example.com, oncall@example.com ,');
        $mailer = new AlertRecordingMailer();
        Hilos::$mail = $mailer;

        new ProtectedModeAlertNotifier()->notifyStuck(self::stuckParams(still: false));

        self::assertSame(['ops@example.com', 'oncall@example.com'], array_column($mailer->sent, 'to'));
        self::assertSame(
            MailTemplateCatalogConstants::PROTECTED_MODE_STUCK,
            $mailer->sent[0]['templateKey'],
        );
        self::assertSame('backup:restore', $mailer->sent[0]['params'][ProtectedModeStuckMailTemplate::PARAM_OPERATION]);
    }

    public function testAnEmptyAddressListSaysSoAndQueuesNothing(): void
    {
        // Legal and ordinary: a node that will never need the watchdog configures no channel, and
        // refusing to start over that was rejected by design.
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name . '=');
        $mailer = new AlertRecordingMailer();
        Hilos::$mail = $mailer;

        new ProtectedModeAlertNotifier()->notifyStuck(self::stuckParams(still: false));

        self::assertSame([], $mailer->sent);
        $agentLog = AgentLogStream::pathFor(dirname($this->logFile), ProtectedModeAlertNotifier::LOG_AGENT_ID, true);
        self::assertFileExists($agentLog);
        self::assertStringContainsString('there is nobody to write to', (string)file_get_contents($agentLog));
    }

    /** Reading recipients and healthy snapshots sends nothing; both mails use the same parsed list. */
    public function testRecipientsAreSharedBySnapshotsAlertsRemindersAndAllClear(): void
    {
        $configured = " Ops@Example.com, ,\toncall@example.com , Ops@Example.com, ";
        $recipientReads = 0;
        $env = $this->getMockBuilder(EnvAccessor::class)->onlyMethods(['effectiveValueFor'])->getMock();
        $env->method('effectiveValueFor')->willReturnCallback(static function (string $key) use ($configured, &$recipientReads): mixed {
            if ($key === EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name) {
                $recipientReads++;

                return $configured;
            }

            return new EnvAccessor()->effectiveValueFor($key);
        });
        Hilos::$env = $env;
        $notifier = new ProtectedModeAlertNotifier();
        $mailer = new AlertRecordingMailer();
        Hilos::$mail = $mailer;
        Hilos::$rt = new class extends RtContext {
            /** No project state is needed; the framework supplies the freeze row. */
            public function configure(): void
            {
            }
        };
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        $watchdog = new ProtectedModeWatchdog($notifier);

        $expected = ['Ops@Example.com', 'oncall@example.com', 'Ops@Example.com'];
        self::assertSame($expected, $notifier->recipients());
        self::assertSame($expected, $watchdog->tick(1)->watchdogAlertEmails);
        self::assertSame($expected, $watchdog->tick(2)->watchdogAlertEmails);
        self::assertSame([], $mailer->sent);

        $notifier->notifyStuck(self::stuckParams(still: false));
        $notifier->notifyStuck(self::stuckParams(still: true));
        $notifier->notifyCleared([]);

        self::assertSame([...$expected, ...$expected, ...$expected], array_column($mailer->sent, 'to'));
        self::assertSame(MailTemplateCatalogConstants::PROTECTED_MODE_STUCK, $mailer->sent[0]['templateKey']);
        self::assertFalse($mailer->sent[0]['params'][ProtectedModeStuckMailTemplate::PARAM_STILL]);
        self::assertTrue($mailer->sent[3]['params'][ProtectedModeStuckMailTemplate::PARAM_STILL]);
        self::assertSame(MailTemplateCatalogConstants::PROTECTED_MODE_CLEARED, $mailer->sent[6]['templateKey']);
        self::assertSame(1, $recipientReads);
    }

    /** A configured empty list is a cached result, and whitespace-only entries do not become addresses. */
    public function testEmptyRecipientConfigurationIsReadOnlyOnce(): void
    {
        $env = $this->getMockBuilder(EnvAccessor::class)->onlyMethods(['effectiveValueFor'])->getMock();
        $env->expects(self::once())->method('effectiveValueFor')
            ->with(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name)->willReturn(" , \t, ");
        Hilos::$env = $env;
        $mailer = new AlertRecordingMailer();
        Hilos::$mail = $mailer;
        $notifier = new ProtectedModeAlertNotifier();

        self::assertSame([], $notifier->recipients());
        self::assertSame([], $notifier->recipients());
        $notifier->notifyStuck(self::stuckParams(still: false));
        self::assertSame([], $mailer->sent);
    }

    /** A failed read stays empty for this notifier and logs its env exception once. */
    public function testUnreadableRecipientsAreCachedWithoutRepeatingTheExceptionLog(): void
    {
        $env = $this->getMockBuilder(EnvAccessor::class)->onlyMethods(['effectiveValueFor'])->getMock();
        $env->expects(self::once())->method('effectiveValueFor')
            ->with(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name)
            ->willThrowException(new EnvInvalidValueException('unreadable recipients'));
        Hilos::$env = $env;
        $mailer = new AlertRecordingMailer();
        Hilos::$mail = $mailer;
        $notifier = new ProtectedModeAlertNotifier();

        self::assertSame([], $notifier->recipients());
        self::assertSame([], $notifier->recipients());
        $notifier->notifyStuck(self::stuckParams(still: false));
        $notifier->notifyStuck(self::stuckParams(still: true));
        $notifier->notifyCleared([]);

        $agentLog = AgentLogStream::pathFor(dirname($this->logFile), ProtectedModeAlertNotifier::LOG_AGENT_ID, true);
        self::assertSame(1, substr_count((string)file_get_contents($agentLog), 'The alert recipient list is unreadable'));
        self::assertSame([], $mailer->sent);
    }

    /** A late synchronization cannot prove what happened before the operation was authorized. */
    public function testMissedBarrierNamesTheUnverifiedIntervalWithoutClaimingCorruption(): void
    {
        foreach ([StateProtectedModeRuntime::PHASE_ACTIVE, StateProtectedModeRuntime::PHASE_VERIFYING] as $phase) {
            foreach (['Worker', null] as $name) {
                $params = self::slaveParams(ProtectedModeSlaveAlertMailTemplate::REASON_MISSED_BARRIER, $phase);
                $params[ProtectedModeSlaveAlertMailTemplate::PARAM_NODE_NAME] = $name;
                $content = new MailTemplateRegistry()->render(MailTemplateCatalogConstants::PROTECTED_MODE_SLAVE_ALERT, $params);
                $label = $name === null ? self::NODE : 'Worker (hilos-node-1)';

                self::assertSame('protected-mode.slave-alert', MailTemplateCatalogConstants::PROTECTED_MODE_SLAVE_ALERT);
                self::assertSame("Protected-mode warning for node {$label}: missed-barrier", $content->subject);
                self::assertSame(
                    "The node {$label} was not confirmed stopped before the operation was allowed to proceed.\n"
                    . "Synchronization has now been requested. Current synchronization cannot establish whether\n"
                    . "this node wrote during the earlier interval. Account for this unverified interval when\n"
                    . "checking the operation result. This warning does not establish data corruption.\n\n"
                    . "Operation: backup:restore\nPhase: {$phase}\nReason: missed-barrier",
                    $content->text,
                );
                self::assertNull($content->html);
            }
        }
    }

    /** The request deadline remains meaningful even while the freeze is being lifted. */
    public function testSyncOverdueNamesTheRequestedPhaseAndTimeoutIndependentlyOfInitiatorProgress(): void
    {
        foreach ([StateProtectedModeRuntime::PHASE_ACTIVE, StateProtectedModeRuntime::PHASE_INACTIVE] as $phase) {
            $content = new MailTemplateRegistry()->render(
                MailTemplateCatalogConstants::PROTECTED_MODE_SLAVE_ALERT,
                self::slaveParams(ProtectedModeSlaveAlertMailTemplate::REASON_SYNC_OVERDUE, $phase),
            );

            self::assertSame('Protected-mode warning for node Worker (hilos-node-1): sync-overdue', $content->subject);
            self::assertSame(
                "The node Worker (hilos-node-1) has not acknowledged the requested protected-mode synchronization\n"
                . "to phase {$phase} within 47 seconds. This warning concerns that request,\n"
                . "independently of progress reported by the operation initiator.\n\n"
                . "Operation: backup:restore\nPhase: {$phase}\nReason: sync-overdue",
                $content->text,
            );
            self::assertNull($content->html);
        }
    }

    /** Every raw mail attempt must find the warning already logged, with no database available. */
    public function testSlaveAlertsQueueRawMailWithoutDatabaseAndLogBeforeEachAttempt(): void
    {
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name . '=ops@example.com, oncall@example.com');
        $agentLog = AgentLogStream::pathFor(dirname($this->logFile), ProtectedModeAlertNotifier::LOG_AGENT_ID, true);
        $messages = [];
        $logsAtSend = [];
        $mailer = $this->getMockBuilder(HilosMailer::class)->onlyMethods(['send'])->getMock();
        $mailer->expects(self::exactly(4))->method('send')->willReturnCallback(
            static function (EmailMessage|MailSendSignalData $message) use (&$messages, &$logsAtSend, $agentLog): void {
                $messages[] = $message;
                $logsAtSend[] = (string)file_get_contents($agentLog);
            },
        );
        Hilos::$mail = $mailer;
        $previousDatabase = Hilos::$db;
        Hilos::$db = null;

        try {
            $notifier = new ProtectedModeAlertNotifier();
            foreach ([ProtectedModeSlaveAlertMailTemplate::REASON_MISSED_BARRIER,
                ProtectedModeSlaveAlertMailTemplate::REASON_SYNC_OVERDUE] as $reason) {
                $params = self::slaveParams($reason);
                $notifier->notifySlaveAlert($params);
                foreach (['ops@example.com', 'oncall@example.com'] as $address) {
                    $message = array_shift($messages);
                    self::assertInstanceOf(MailSendSignalData::class, $message);
                    self::assertSame($address, $message->to);
                    self::assertSame(HilosMailer::shardKeyForAddress($address), $message->shardKey);
                    self::assertSame(MailTemplateCatalogConstants::PROTECTED_MODE_SLAVE_ALERT, $message->templateKey);
                    self::assertSame($params, $message->params);
                    self::assertStringContainsString(
                        new MailTemplateRegistry()->render(MailTemplateCatalogConstants::PROTECTED_MODE_SLAVE_ALERT, $params)->text,
                        array_shift($logsAtSend),
                    );
                }
            }
            self::assertNull(Hilos::$db);
        } finally {
            Hilos::$db = $previousDatabase;
        }
    }

    /** A missing or failing mail channel leaves the warning intact and does not stop the next recipient attempt. */
    public function testSlaveWarningsRemainLoggedWithEmptyRecipientsOrARefusedQueue(): void
    {
        foreach ([ProtectedModeSlaveAlertMailTemplate::REASON_MISSED_BARRIER,
            ProtectedModeSlaveAlertMailTemplate::REASON_SYNC_OVERDUE] as $reason) {
            putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name . '=');
            $mailer = new AlertRecordingMailer();
            Hilos::$mail = $mailer;
            $params = self::slaveParams($reason);

            new ProtectedModeAlertNotifier()->notifySlaveAlert($params);

            self::assertSame([], $mailer->sent);
            $agentLog = AgentLogStream::pathFor(dirname($this->logFile), ProtectedModeAlertNotifier::LOG_AGENT_ID, true);
            $log = (string)file_get_contents($agentLog);
            self::assertStringContainsString('there is nobody to write to', $log);
            self::assertStringContainsString("Reason: {$reason}", $log);

            putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name . '=ops@example.com, oncall@example.com');
            $mailer = $this->getMockBuilder(HilosMailer::class)->onlyMethods(['send'])->getMock();
            $mailer->expects(self::exactly(2))->method('send')->willThrowException(new MailBusyException());
            Hilos::$mail = $mailer;

            new ProtectedModeAlertNotifier()->notifySlaveAlert($params);

            $log = (string)file_get_contents($agentLog);
            self::assertStringContainsString('could not be queued for ops@example.com', $log);
            self::assertStringContainsString('could not be queued for oncall@example.com', $log);
            self::assertSame(2, substr_count($log, "Reason: {$reason}"));
        }
    }

    /**
     * @param string $reason Slave warning reason
     * @param string $phase Requested protected-mode phase
     * @return array<string, mixed> The six approved slave template params
     */
    private static function slaveParams(string $reason, string $phase = StateProtectedModeRuntime::PHASE_ACTIVE): array
    {
        return [
            ProtectedModeSlaveAlertMailTemplate::PARAM_NODE_ID => self::NODE,
            ProtectedModeSlaveAlertMailTemplate::PARAM_NODE_NAME => 'Worker',
            ProtectedModeSlaveAlertMailTemplate::PARAM_OPERATION => CliCommands::BACKUP_RESTORE,
            ProtectedModeSlaveAlertMailTemplate::PARAM_PHASE => $phase,
            ProtectedModeSlaveAlertMailTemplate::PARAM_REASON => $reason,
            ProtectedModeSlaveAlertMailTemplate::PARAM_TIMEOUT_SECONDS => 47,
        ];
    }

    /**
     * The params the watchdog hands the stuck template, with the approved copy's own values.
     *
     * @param bool $still Whether these params describe a reminder
     * @return array<string, mixed> Template params
     */
    private static function stuckParams(bool $still): array
    {
        return [
            ProtectedModeStuckMailTemplate::PARAM_NODE_ID => self::NODE,
            ProtectedModeStuckMailTemplate::PARAM_NODE_NAME => 'Worker',
            ProtectedModeStuckMailTemplate::PARAM_OPERATION => 'backup:restore',
            ProtectedModeStuckMailTemplate::PARAM_PHASE => 'active',
            ProtectedModeStuckMailTemplate::PARAM_INITIATOR => 'hilos_backup agent on hilos-node-1',
            ProtectedModeStuckMailTemplate::PARAM_PROBLEM => 'the initiator has reported no progress for 14 minutes',
            ProtectedModeStuckMailTemplate::PARAM_FROZEN_SINCE => '16 Aug 2026 03:12:44 UTC',
            ProtectedModeStuckMailTemplate::PARAM_FROZEN_FOR => '32 minutes',
            ProtectedModeStuckMailTemplate::PARAM_REPEAT_EVERY => '15 minutes',
            ProtectedModeStuckMailTemplate::PARAM_STILL => $still,
        ];
    }
}

/**
 * A mailer that records what would have been queued instead of queueing it.
 *
 * Subclassed rather than faked behind an interface because the raw-send intake is what the alert
 * deliberately rides: a test that replaced the mailer wholesale would stop proving the alert takes
 * the path that needs no database.
 */
final class AlertRecordingMailer extends HilosMailer
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
