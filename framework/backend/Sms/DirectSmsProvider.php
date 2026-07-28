<?php

declare(strict_types=1);

namespace Hilos\Sms;

/**
 * DirectSmsProvider - an SMS provider that settles a send in-process (HIL-285).
 *
 * The offline counterpart of {@see HttpSmsProvider}, mirroring
 * {@see \Hilos\Auth\OAuth\OfflineOAuthProvider}: it resolves the send synchronously with no
 * network I/O. {@see StubSmsProvider} is the dev/e2e implementation - it writes a verifiable
 * .txt artifact (and a masked log line) instead of calling a gateway, the SMS analogue of the
 * file mail transport.
 */
interface DirectSmsProvider extends SmsProviderInterface
{
    /**
     * Sends one message in-process and returns its settled result.
     *
     * @param SmsMessage $message Recipient message to send
     * @param float $nowMs Current time in milliseconds
     * @return SmsSendResult Settled result of the in-process send
     */
    public function send(SmsMessage $message, float $nowMs): SmsSendResult;
}
