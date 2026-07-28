<?php

declare(strict_types=1);

namespace Hilos\Sms;

/**
 * SmsMessage - a ready-to-send text message addressed to one recipient (HIL-285).
 *
 * The value the SMS subsystem hands a provider: the resolved E.164 recipient number and
 * the rendered single-line text. The sender id is not carried here - it is channel config
 * (SMS_FROM / the `from` field), applied when the provider builds its request, so one
 * recipient message stays portable across providers. The text is already truncated to the
 * channel's segment budget before a message is built (see {@see SmsText}).
 */
final class SmsMessage
{
    /**
     * @param string $to Recipient number in E.164
     * @param string $text Rendered single-line message body
     */
    public function __construct(
        public readonly string $to,
        public readonly string $text,
    ) {
    }
}
