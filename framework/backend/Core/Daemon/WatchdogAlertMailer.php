<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailConfigException;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\MailMessageEncoder;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportConfig;
use Hilos\Mail\MailTransportFactory;
use Hilos\Mail\MailTransportInterface;
use Hilos\Mail\SmtpSecurity;
use Hilos\ProtectedMode\ProtectedModeAlertNotifier;
use Hilos\Utils\Logger;
use Throwable;

/**
 * WatchdogAlertMailer - the one thing the container watchdog says when things go wrong (HIL-617).
 *
 * Two occasions, and no others: the daemon failed to start
 * {@see EnvConstants::DAEMON_FAILED_START_THRESHOLD} times in a row, and the watchdog is
 * exiting after a failure of its own. There is no "recovered" mail and no timed reminder —
 * the watchdog stays simple, and a watchdog for the watchdog is exactly what this must not
 * become.
 *
 * **The mail is its own, not the application's.** It reads `WATCHDOG_ALERT_*` rather than
 * `MAIL_*`, because it has to be able to report itself on a box where the product mail is not
 * configured at all; for the same reason those variables belong to the watchdog and no other
 * process may borrow them. Unlike `MAIL_*` there is no transport switch: an empty host, From
 * or To address means "not configured", which is said once in the log instead of quietly
 * writing a .eml nobody will read.
 *
 * **The send is direct and synchronous**, which {@see ProtectedModeAlertNotifier} is not:
 * that one hands the message to {@see HilosMailer::send()}, which queues a signal
 * for an agent living inside a worker. The watchdog writes precisely when there may be no
 * daemon at all, so it pumps the transport itself and waits up to `WATCHDOG_ALERT_TIMEOUT_MS`
 * (default 5000, half the product's, because a dying process must not sit on a socket).
 *
 * **Nothing here changes what the watchdog does.** Every failure — unset configuration, a
 * refused relay, an exception out of the mail code — is one line in the log and no more: no
 * retry, no delay beyond the timeout, no bearing on the exit code.
 *
 * The transport is a constructor argument rather than something built inside, so both
 * occasions are provable in a unit test with a file transport in its place.
 */
final class WatchdogAlertMailer
{
    /** What every subject line starts with, so an operator can filter these out of a mailbox. */
    private const string SUBJECT_PREFIX = '[Hilos watchdog]';

    /** How long one pump step sleeps while the transport works, in microseconds. */
    private const int POLL_INTERVAL_US = 10000;

    /** How much of the reason a mail body carries, in characters. */
    private const int REASON_MAX_LENGTH = 200;

    /** What the node calls itself when the host name is unavailable. */
    private const string UNKNOWN_NODE = 'unknown';

    /**
     * @param MailTransportInterface $transport Transport the alert is pumped through
     * @param string $toAddress The single operator address the alert goes to
     * @param int $timeoutMs How long one send may take before it is abandoned
     */
    public function __construct(
        private readonly MailTransportInterface $transport,
        private readonly string $toAddress,
        private readonly int $timeoutMs,
    ) {
    }

    /**
     * Builds the mailer from `WATCHDOG_ALERT_*`, or reports why it cannot.
     *
     * Called once at watchdog startup rather than at the incident, so "the watchdog mail is
     * not configured" is visible when the node comes up and a dying process never has to read
     * the environment.
     *
     * The SMTP transport is pinned explicitly: left to auto-select, {@see MailTransportFactory}
     * would answer an empty host with the file transport, and for this mailer an empty host is
     * the definition of unconfigured. `fileDir` is empty for the same reason — there is no file
     * fallback to write to.
     *
     * @return ?self The configured mailer, or null when the watchdog mail is unusable
     */
    public static function fromEnv(): ?self
    {
        try {
            $host = trim(Hilos::$env->string(EnvConstants::WATCHDOG_ALERT_SMTP_HOST));
            $fromAddress = trim(Hilos::$env->string(EnvConstants::WATCHDOG_ALERT_FROM_ADDRESS));
            $toAddress = trim(Hilos::$env->string(EnvConstants::WATCHDOG_ALERT_TO_ADDRESS));

            $missing = self::missingNames($host, $fromAddress, $toAddress);
            if ($missing !== []) {
                Logger::error(
                    'Watchdog alert mail is not configured, so no incident will be mailed: '
                    . implode(', ', $missing) . ' ' . (count($missing) === 1 ? 'is' : 'are') . ' empty',
                );

                return null;
            }

            $timeoutMs = Hilos::$env->int(EnvConstants::WATCHDOG_ALERT_TIMEOUT_MS);
            $config = new MailTransportConfig(
                fromAddress: $fromAddress,
                fileDir: '',
                transport: MailTransportFactory::TRANSPORT_SMTP,
                smtpHost: $host,
                smtpPort: Hilos::$env->int(EnvConstants::WATCHDOG_ALERT_SMTP_PORT),
                security: self::security(),
                username: self::nullIfEmpty(Hilos::$env->string(EnvConstants::WATCHDOG_ALERT_SMTP_USERNAME)),
                password: self::nullIfEmpty(Hilos::$env->string(EnvConstants::WATCHDOG_ALERT_SMTP_PASSWORD)),
                timeoutMs: $timeoutMs,
            );

            return new self(new MailTransportFactory()->create($config), $toAddress, $timeoutMs);
        } catch (Throwable $e) {
            Logger::error(
                'Watchdog alert mail could not be set up, so no incident will be mailed: ' . $e->getMessage(),
            );

            return null;
        }
    }

    /**
     * Mails that the daemon has failed to start this many times in a row.
     *
     * The caller sends this once per run of failures, not once per failure: a node restarting
     * every {@see EnvConstants::DAEMON_MIN_RESTART_INTERVAL} seconds would otherwise mail at
     * that rate. The loud log line the watchdog already writes is unaffected and keeps its
     * full error-log tail — only the last non-empty line of it travels, because an application
     * log with stack traces has no business going out through a mail relay.
     *
     * @param int $failedStarts How many starts in a row died before reaching the minimum uptime
     * @param float $lastUptimeSeconds How long the last failed start survived, in seconds
     * @param string $errorLogTail Tail of the daemon error log the watchdog already read
     */
    public function sendDaemonFailedStart(int $failedStarts, float $lastUptimeSeconds, string $errorLogTail): void
    {
        $node = self::nodeName();
        $this->send(
            "{$node}: daemon failed to start {$failedStarts} times in a row",
            $this->header($node)
            . "The daemon failed to start {$failedStarts} times in a row; the last attempt survived "
            . number_format($lastUptimeSeconds, 2) . "s.\n"
            . "The watchdog keeps retrying and does not give up.\n"
            . "\n"
            . 'Reason: ' . self::shortReason($errorLogTail) . "\n",
        );
    }

    /**
     * Mails that the watchdog is going away and will not bring the daemon back.
     *
     * This is the honest half of the feature and the reason the wording says so plainly:
     * nothing inside Hilos restarts a watchdog. It is also the half with a hard limit — a
     * `kill -9`, the OOM killer or a container that dies outright leave no chance to write
     * anything at all, and that is watched for from outside the framework.
     *
     * @param string $reason What the watchdog failed on, already rendered by the caller
     */
    public function sendWatchdogExiting(string $reason): void
    {
        $node = self::nodeName();
        $this->send(
            "{$node}: watchdog is exiting after its own failure",
            $this->header($node)
            . 'The watchdog is exiting after a failure of its own and will not restart the daemon again.'
            . " Nothing inside Hilos will bring it back — restart the container.\n"
            . "\n"
            . 'Reason: ' . $reason . "\n",
        );
    }

    /**
     * Sends one alert and lets nothing out of this method.
     *
     * The blanket catch is the point rather than laziness: this runs on a node that is already
     * in trouble, and an exception escaping here would replace the incident the operator needs
     * to hear about with a failure of the reporting itself. Undelivered outcomes, a busy or
     * unreadable transport, a broken relay — each is one log line and nothing else.
     *
     * @param string $subject Subject line, after the shared prefix
     * @param string $body Plain-text body
     */
    private function send(string $subject, string $body): void
    {
        try {
            $startedMs = microtime(true) * TimeConstants::MS_PER_SECOND;
            $this->transport->start(
                new EmailMessage(to: $this->toAddress, subject: self::SUBJECT_PREFIX . ' ' . $subject, text: $body),
                $startedMs,
            );

            $outcome = $this->pump($startedMs);
            if ($outcome === null) {
                Logger::error("Watchdog alert mail '{$subject}' did not settle within {$this->timeoutMs}ms");
            } elseif ($outcome->delivered === false) {
                Logger::error("Watchdog alert mail '{$subject}' was not delivered: {$outcome->errorDetail}");
            }
        } catch (Throwable $e) {
            Logger::error("Watchdog alert mail '{$subject}' failed to send: " . $e->getMessage());
        } finally {
            $this->transport->close();
        }
    }

    /**
     * Drives the transport until it settles or the timeout runs out.
     *
     * Blocking here is deliberate and is the whole reason this mailer exists apart from the
     * mail agent: there is no loop left to pump the send from. The cost is bounded by
     * `WATCHDOG_ALERT_TIMEOUT_MS` and paid at a moment when the node is down anyway.
     *
     * @param float $startedMs When the send was started, in milliseconds
     * @return ?MailSendOutcome The settled outcome, or null when the send was abandoned unsettled
     */
    private function pump(float $startedMs): ?MailSendOutcome
    {
        while ($this->transport->hasResult() === false) {
            if (microtime(true) * TimeConstants::MS_PER_SECOND - $startedMs >= $this->timeoutMs) {
                return null;
            }

            usleep(self::POLL_INTERVAL_US);
            $this->transport->tick(microtime(true) * TimeConstants::MS_PER_SECOND);
        }

        return $this->transport->consumeResult();
    }

    /**
     * The two lines every alert body opens with.
     *
     * @param string $node What this node calls itself
     * @return string Node and timestamp lines, followed by a blank line
     */
    private function header(string $node): string
    {
        return "Node:   {$node}\n"
            . 'Time:   ' . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "\n";
    }

    /**
     * Picks the one line of the error-log tail worth mailing.
     *
     * The last non-empty line is the innermost thing that went wrong; everything above it is
     * the stack that led there, which stays in the log where it belongs.
     *
     * @param string $errorLogTail Tail of the daemon error log
     * @return string A single trimmed line, cut to {@see REASON_MAX_LENGTH} characters
     */
    private static function shortReason(string $errorLogTail): string
    {
        $reason = '';
        foreach (explode("\n", $errorLogTail) as $line) {
            if (trim($line) !== '') {
                $reason = trim($line);
            }
        }

        return mb_substr($reason, 0, self::REASON_MAX_LENGTH);
    }

    /**
     * What this node calls itself in the subject and the body.
     *
     * `gethostname()` is a `uname(2)` read of the local host name — no socket, no resolver —
     * which is why it is safe here and why the `BLOCKING-RESOLUTION` guard does not name it.
     *
     * @return string The host name, or a stand-in when the host does not report one
     */
    private static function nodeName(): string
    {
        $node = gethostname();

        return $node === false ? self::UNKNOWN_NODE : $node;
    }

    /**
     * Names the settings whose emptiness makes the watchdog mail unusable.
     *
     * The From address is here alongside the host and the recipient because
     * {@see MailMessageEncoder} takes From from the configuration: an empty one
     * produces a letter a relay rejects, which is a silent failure rather than a sent alert.
     *
     * @param string $host Configured SMTP host
     * @param string $fromAddress Configured From address
     * @param string $toAddress Configured recipient address
     * @return list<string> Env names that are empty, in declaration order
     */
    private static function missingNames(string $host, string $fromAddress, string $toAddress): array
    {
        $missing = [];
        if ($host === '') {
            $missing[] = EnvConstants::WATCHDOG_ALERT_SMTP_HOST->name;
        }

        if ($fromAddress === '') {
            $missing[] = EnvConstants::WATCHDOG_ALERT_FROM_ADDRESS->name;
        }

        if ($toAddress === '') {
            $missing[] = EnvConstants::WATCHDOG_ALERT_TO_ADDRESS->name;
        }

        return $missing;
    }

    /**
     * Reads the transport-security mode the watchdog relay is reached with.
     *
     * @return SmtpSecurity Matched transport-security mode
     * @throws EnvException When the env value is missing from the catalog or has the wrong type
     * @throws MailConfigException When the value names no known mode
     */
    private static function security(): SmtpSecurity
    {
        $value = Hilos::$env->string(EnvConstants::WATCHDOG_ALERT_SMTP_SECURITY);

        return SmtpSecurity::tryFrom(strtolower(trim($value)))
            ?? throw new MailConfigException(
                EnvConstants::WATCHDOG_ALERT_SMTP_SECURITY->name . " '{$value}' is not one of tls|starttls|none",
            );
    }

    /**
     * @param string $value Raw env string
     * @return ?string The trimmed value, or null when it is empty
     */
    private static function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
