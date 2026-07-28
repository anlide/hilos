<?php

declare(strict_types=1);

namespace Hilos\Sms;

use Hilos\Utils\Logger;

/**
 * StubSmsProvider - a dev/e2e provider that writes each message as a .txt file (HIL-285).
 *
 * The auto provider when no gateway endpoint is configured, and the explicit choice under
 * SMS_PROVIDER=stub. It writes the message to the stub directory as a verifiable artifact -
 * the SMS analogue of {@see \Hilos\Mail\FileMailTransport} - so dev and e2e can assert a code
 * was "sent" without a real gateway or a live number. The artifact carries the full message
 * (dev reads it); the log line never does - only the masked number and length, so an Auth
 * code routed as a raw send is never exposed. The send settles synchronously.
 */
final class StubSmsProvider implements DirectSmsProvider
{
    public function __construct(
        private readonly SmsChannelConfig $config,
    ) {
    }

    /**
     * @return string The `stub` provider key
     */
    public function getKey(): string
    {
        return SmsChannelConfig::PROVIDER_STUB;
    }

    /**
     * Writes the message as a .txt artifact (when a directory is configured) and logs it masked.
     *
     * @param SmsMessage $message Recipient message to send
     * @param float $nowMs Current time in milliseconds, used to name the artifact
     * @return SmsSendResult Delivered on success, or a permanent failure when the write fails
     */
    public function send(SmsMessage $message, float $nowMs): SmsSendResult
    {
        if ($this->config->fileDir !== '') {
            $result = $this->write($message, $nowMs);
            if (!$result->delivered) {
                return $result;
            }
        }

        Logger::info(
            'SMS delivered (stub)',
            ['to' => SmsText::maskNumber($message->to), 'length' => mb_strlen($message->text)],
        );

        return SmsSendResult::delivered();
    }

    /**
     * Writes the message to a uniquely named .txt artifact in the stub directory.
     *
     * @param SmsMessage $message Recipient message to write
     * @param float $nowMs Current time in milliseconds, used to name the artifact
     * @return SmsSendResult Delivered on success, or a permanent failure when the write fails
     */
    private function write(SmsMessage $message, float $nowMs): SmsSendResult
    {
        if (!is_dir($this->config->fileDir) && !mkdir($this->config->fileDir, 0o775, true) && !is_dir($this->config->fileDir)) {
            return SmsSendResult::failed('sms file directory is not writable', true);
        }

        $content = "To: {$message->to}\nFrom: {$this->config->from}\nText: {$message->text}\n";
        $path = $this->config->fileDir . '/' . (int)$nowMs . '-' . substr(hash('sha256', $content), 0, 16) . '.txt';
        if (file_put_contents($path, $content) === false) {
            return SmsSendResult::failed('sms file could not be written', true);
        }

        return SmsSendResult::delivered();
    }
}
