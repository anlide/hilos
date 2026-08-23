<?php

declare(strict_types=1);

namespace Hilos\Sms;

use Hilos\Auth\OAuth\OfflineOAuthProvider;

/**
 * DirectSmsProvider - an SMS provider that settles a send in-process (HIL-285).
 *
 * The offline counterpart of {@see HttpSmsProvider}, mirroring
 * {@see OfflineOAuthProvider}: it resolves the send synchronously with no
 * network I/O. {@see StubSmsProvider} is the one implementation - it writes a masked log line
 * instead of calling a gateway, so a project with no gateway configured still runs.
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
