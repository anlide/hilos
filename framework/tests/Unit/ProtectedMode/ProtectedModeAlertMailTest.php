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
use Hilos\Mail\Template\MailTemplateRegistry;
use Hilos\Mail\Template\ProtectedModeClearedMailTemplate;
use Hilos\Mail\Template\ProtectedModeStuckMailTemplate;
use Hilos\ProtectedMode\ProtectedModeAlertNotifier;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-protected-mode-alert');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousMailer = Hilos::$mail;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::MAIL_WORKER_COUNT->name . '=1');
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        putenv(EnvConstants::MAIL_WORKER_COUNT->name);
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name);
        Hilos::$env = $this->previousEnv;
        Hilos::$mail = $this->previousMailer;

        parent::tearDown();
    }

    public function testTheFirstAlertRendersTheApprovedBody(): void
    {
        $content = new MailTemplateRegistry()->render(
            MailTemplateCatalogConstants::PROTECTED_MODE_STUCK,
            self::stuckParams(still: false),
            null,
        );

        self::assertSame('Node hilos-node-1 is stuck in maintenance', $content->subject);
        self::assertStringContainsString(
            'The node hilos-node-1 has been frozen for maintenance since 16 Aug 2026 03:12:44 UTC (32 minutes)'
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

        self::assertSame('Node hilos-node-1 is still stuck in maintenance (1h 02m)', $reminder->subject);
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
                ProtectedModeClearedMailTemplate::PARAM_OPERATION => 'backup:restore',
                ProtectedModeClearedMailTemplate::PARAM_LIFTED_AT => '16 Aug 2026 04:20:10 UTC',
                ProtectedModeClearedMailTemplate::PARAM_HELD_FOR => '1h 07m',
                ProtectedModeClearedMailTemplate::PARAM_STUCK_FOR => '55 minutes',
            ],
            null,
        );

        self::assertSame('Node hilos-node-1 is out of maintenance', $content->subject);
        self::assertStringContainsString(
            'The freeze on hilos-node-1 was lifted at 16 Aug 2026 04:20:10 UTC. It had held for 1h 07m,'
            . ' 55 minutes of them with nothing running behind it.',
            $content->text,
        );
        self::assertStringContainsString('Lifted by: protected-mode:open', $content->text);
        self::assertStringContainsString('No further alerts will be sent for this freeze.', $content->text);
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
        self::assertStringContainsString('there is nobody to write to', (string)file_get_contents($this->logFile));
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
