<?php

declare(strict_types=1);

namespace Hilos\Mail;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Notification\Delivery\NotificationDispatcher;

/**
 * HilosMailer - the send seam of the mail subsystem (HIL-197).
 *
 * The facade global {@see Hilos::$mail}. {@see send()} never does mail I/O at
 * the call site: it derives the pool shard from the recipient address and queues the
 * raw-send agent signal ({@see HilosSignalConstants::HILOS_MAIL_SEND}), so the actual
 * SMTP dialog runs off in the sharded `hilos_mail` agent pool. This is the raw-send
 * intake (Auth codes, magic links) that bypasses the hilos_notification tables — the
 * notification-delivery intake is the channel path through {@see NotificationDispatcher}.
 *
 * A caller either hands a fully-formed {@see MailSendSignalData} (already carrying its
 * shard key, and optionally a template key resolved agent-side) or an inline
 * {@see EmailMessage} the mailer shards and wraps. The raw-send wire contract carries
 * only the recipient address and the inline/template body — an EmailMessage display
 * name or reply-to is not part of it (the agent applies transport-level From defaults).
 *
 * Best-effort like the notifier: when the signal router is not initialized (e.g. a CLI
 * context) the signal simply reaches no one.
 */
class HilosMailer
{
    /**
     * Queues a message to the sharded mail agent pool without sending it in place.
     *
     * @param EmailMessage|MailSendSignalData $message Inline message the mailer shards, or a ready raw-send payload
     * @throws EnvException When MAIL_WORKER_COUNT is unreadable while sharding an inline EmailMessage
     * @throws ValidationException When an inline EmailMessage names no recipient address
     * @throws InvalidArgumentException When the mail send signal cannot be named or queued
     */
    public function send(EmailMessage|MailSendSignalData $message): void
    {
        $data = $message instanceof MailSendSignalData
            ? $message
            : new MailSendSignalData(
                to: $message->to,
                shardKey: self::shardKeyForAddress($message->to),
                subject: $message->subject,
                text: $message->text,
                html: $message->html,
            );

        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_MAIL_SEND),
            signalData: new AgentSignalData(
                data: $data,
            ),
        );
    }

    /**
     * Derives the mail pool shard key for a recipient address.
     *
     * Sharding by the normalized address (not by a notification or user id) keeps every
     * message to one recipient on the same pool instance, so its ordering — and any
     * future per-address rate limit — stays local to that agent. The mail channel shards
     * its notification-delivery intake by the same rule so both intakes co-locate.
     *
     * @param string $address Recipient email address
     * @return int Positive shard key in the range 1..MAIL_WORKER_COUNT
     * @throws EnvException When MAIL_WORKER_COUNT is unreadable
     */
    public static function shardKeyForAddress(string $address): int
    {
        $workerCount = max(1, Hilos::$env?->int(EnvConstants::MAIL_WORKER_COUNT) ?? 1);

        return 1 + (int)(crc32(strtolower(trim($address))) % $workerCount);
    }
}
