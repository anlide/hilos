<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\MailTransportConfig;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\ProtectedModeClearedMailTemplate;
use Hilos\Mail\Template\ProtectedModeStuckMailTemplate;
use Hilos\Backup\RestoreNotifier;
use Hilos\Users\AdminAudience;
use Hilos\Utils\Logger;
use Throwable;

/**
 * ProtectedModeAlertNotifier - mails an operator that a node is frozen and not coming back.
 *
 * Shaped after {@see RestoreNotifier}, with the one difference that decides everything else about
 * it: **there is no database anywhere in this path.** The alarm fires precisely when the database
 * may be half-written or unreadable, and the master may not read it in any case, so the recipients
 * come from an environment address list rather than from {@see AdminAudience}, and the message
 * rides the raw-send intake ({@see HilosMailer::send()}) rather than a notification - a
 * NotificationDraft is persisted before it is delivered, i.e. it needs the very database the alert
 * is about. SMTP settings are env-only ({@see MailTransportConfig}), so the whole path holds with
 * no database at all.
 *
 * Everything it is told is already rendered: the watchdog owns the clock and the wording of the
 * problem, and this class owns who hears about it. An empty address list is legal and is the
 * ordinary case for a node that will never need a watchdog - it is said once in the log and
 * nothing is sent. Refusing to start over unconfigured mail was rejected for the same reason.
 *
 * Delivery failure never changes anything: the log line the watchdog wrote is the report that
 * cannot fail, and the reminder is driven by the phase and the clock rather than by whether the
 * previous mail landed.
 */
final class ProtectedModeAlertNotifier
{
    /** @var string Agent id this notifier's own log lines are filed under */
    private const string LOG_AGENT_ID = 'protected-mode-watchdog';

    /** @var string What separates one recipient address from the next in the environment value */
    private const string ADDRESS_SEPARATOR = ',';

    /**
     * Mails the operators that this node is frozen with nothing happening behind it.
     *
     * @param array<string, mixed> $params Rendered template params, keyed by the template's PARAM_*
     */
    public function notifyStuck(array $params): void
    {
        $this->mailEveryone(MailTemplateCatalogConstants::PROTECTED_MODE_STUCK, $params, 'stuck-freeze alert');
    }

    /**
     * Mails the operators that the freeze they were alerted about has been lifted.
     *
     * @param array<string, mixed> $params Rendered template params, keyed by the template's PARAM_*
     */
    public function notifyCleared(array $params): void
    {
        $this->mailEveryone(MailTemplateCatalogConstants::PROTECTED_MODE_CLEARED, $params, 'freeze-lifted notice');
    }

    /**
     * Queues one message per configured recipient, and says so when there are none.
     *
     * One message per address rather than one with several recipients, because the mail pool shards
     * by address ({@see HilosMailer::shardKeyForAddress()}): each lands on the agent that owns that
     * address, and one unreachable mailbox cannot hold up the others.
     *
     * Every failure is swallowed after being logged. This runs on the master, inside the daemon
     * loop, over a node that is already in trouble - an exception escaping here would end the run
     * loop and kill the one process able to report the freeze at all.
     *
     * @param string $templateKey Mail template to render
     * @param array<string, mixed> $params Rendered template params
     * @param string $what What is being sent, for the log line
     */
    private function mailEveryone(string $templateKey, array $params, string $what): void
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "A {$what} is up and there is nobody to write to: "
                . EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name . ' names no address',
            );

            return;
        }

        foreach ($recipients as $address) {
            try {
                Hilos::$mail?->send(new MailSendSignalData(
                    to: $address,
                    shardKey: HilosMailer::shardKeyForAddress($address),
                    templateKey: $templateKey,
                    params: $params,
                ));
            } catch (Throwable $e) {
                Logger::logAgentError(
                    self::LOG_AGENT_ID,
                    "The {$what} could not be queued for {$address}: {$e->getMessage()}",
                );
            }
        }
    }

    /**
     * The addresses the alert goes to, as the environment names them.
     *
     * Blank entries are dropped rather than refused: an operator editing a list by hand leaves a
     * trailing comma, and an alert about an unreachable node is not the place to be strict about
     * punctuation. An unreadable value reads as no recipients at all, which the caller reports.
     *
     * @return list<string> Recipient addresses, empty when none are configured
     */
    private function recipients(): array
    {
        try {
            $configured = Hilos::$env->string(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS);
        } catch (EnvException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                'The alert recipient list is unreadable, so nothing can be sent: ' . $e->getMessage(),
            );

            return [];
        }

        $addresses = [];
        foreach (explode(self::ADDRESS_SEPARATOR, $configured) as $address) {
            $address = trim($address);
            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
