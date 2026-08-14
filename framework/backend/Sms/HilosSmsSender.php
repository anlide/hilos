<?php

declare(strict_types=1);

namespace Hilos\Sms;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\HilosMailer;
use Hilos\Notification\Delivery\NotificationDispatcher;
use Hilos\Sms\DTO\SmsSendSignalData;

/**
 * HilosSmsSender - the send seam of the SMS subsystem (HIL-285).
 *
 * The facade global {@see Hilos::$sms}, mirroring {@see HilosMailer}.
 * {@see send()} never does SMS I/O at the call site: it derives the pool shard from the
 * recipient number and queues the raw-send agent signal
 * ({@see HilosSignalConstants::HILOS_SMS_SEND}), so the actual gateway request runs off in
 * the sharded `hilos_sms` agent pool. This is the raw-send intake (Auth login/add codes)
 * that bypasses the hilos_notification tables - the notification-delivery intake is the
 * channel path through {@see NotificationDispatcher}.
 *
 * A caller either hands a fully-formed {@see SmsSendSignalData} (already carrying its shard
 * key, and optionally a template key resolved agent-side) or an inline {@see SmsMessage} the
 * sender shards and wraps. Best-effort like the mailer: when the signal router is not
 * initialized (e.g. a CLI context) the signal simply reaches no one.
 */
class HilosSmsSender
{
    /**
     * Queues a message to the sharded SMS agent pool without sending it in place.
     *
     * @param SmsMessage|SmsSendSignalData $message Inline message the sender shards, or a ready raw-send payload
     * @throws EnvException When SMS_WORKER_COUNT is unreadable while sharding an inline SmsMessage
     * @throws ValidationException When an inline SmsMessage names no recipient number
     */
    public function send(SmsMessage|SmsSendSignalData $message): void
    {
        $data = $message instanceof SmsSendSignalData
            ? $message
            : new SmsSendSignalData(
                to: $message->to,
                shardKey: self::shardKeyForNumber($message->to),
                text: $message->text,
            );

        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_SMS_SEND),
            signalData: new AgentSignalData(
                data: $data,
            ),
        );
    }

    /**
     * Derives the SMS pool shard key for a recipient number.
     *
     * Sharding by the normalized number (not by a notification or user id) keeps every message
     * to one recipient on the same pool instance, so its ordering - and any future per-number
     * rate limit - stays local to that agent. The SMS channel shards its notification-delivery
     * intake by the same rule so both intakes co-locate.
     *
     * @param string $number Recipient number in E.164
     * @return int Positive shard key in the range 1..SMS_WORKER_COUNT
     * @throws EnvException When SMS_WORKER_COUNT is unreadable
     */
    public static function shardKeyForNumber(string $number): int
    {
        $workerCount = max(1, Hilos::$env?->int(EnvConstants::SMS_WORKER_COUNT) ?? 1);

        return 1 + (int)(crc32(self::normalize($number)) % $workerCount);
    }

    /**
     * Normalizes a number for stable sharding: trims and drops internal whitespace.
     *
     * @param string $number Recipient number
     * @return string Normalized number used as the shard input
     */
    private static function normalize(string $number): string
    {
        return preg_replace('/\s+/', '', trim($number)) ?? trim($number);
    }
}
