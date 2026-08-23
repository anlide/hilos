<?php

declare(strict_types=1);

namespace Hilos\Sms;

use Hilos\Utils\Logger;

/**
 * StubSmsProvider - the safe default for a project with no SMS gateway (HIL-285, HIL-653).
 *
 * The auto provider when no gateway endpoint is configured, and the explicit choice under
 * SMS_PROVIDER=stub. It sends nothing and reports the send settled, so a project that has
 * not bought a gateway yet runs its login and notification flows instead of failing on
 * them. The log line carries only the masked number and the text length - never the text,
 * so an Auth code routed as a raw send is not exposed in a log a whole team reads.
 *
 * What it is NOT is a way to read a code. It used to write each message as a .txt artifact,
 * and that artifact was mistaken for a readable channel: it was absent on a local stand,
 * it landed in the work tree owned by the container's user, and a stale one from an earlier
 * run was once read as the code a person had just asked for. Reading is the stand gateway's
 * job now (framework/docker/stand-gateway), which forwards a caught message to the stand's
 * Mailpit as a letter - so a stand that wants to read its SMS configures an endpoint, and
 * the stub is left meaning exactly one thing.
 */
final class StubSmsProvider implements DirectSmsProvider
{
    /**
     * @return string The `stub` provider key
     */
    public function getKey(): string
    {
        return SmsChannelConfig::PROVIDER_STUB;
    }

    /**
     * Logs the message masked and reports the send settled.
     *
     * @param SmsMessage $message Recipient message to send
     * @param float $nowMs Current time in milliseconds, unused by a provider that sends nothing
     * @return SmsSendResult Always delivered
     */
    public function send(SmsMessage $message, float $nowMs): SmsSendResult
    {
        Logger::info(
            'SMS delivered (stub)',
            ['to' => SmsText::maskNumber($message->to), 'length' => mb_strlen($message->text)],
        );

        return SmsSendResult::delivered();
    }
}
