<?php

declare(strict_types=1);

namespace Hilos\Telegram;

/**
 * TelegramGatewayResult - one Gateway answer, read out of its envelope (HIL-492).
 *
 * Every Gateway call answers the same two shapes - `{"ok":true,"result":{…}}` or
 * `{"ok":false,"error":"…"}` - so both calls read into this one type rather than two
 * near-identical ones. An `ok:false` is the Gateway working and saying no, which is
 * why it lives here as a value and not as an exception.
 *
 * {@see reason} is a domain sentence for the agent log and never reaches the client:
 * the wire carries a stable reason code, so no provider detail escapes to a guest.
 */
final readonly class TelegramGatewayResult
{
    /**
     * @param bool $accepted Whether the Gateway accepted the call
     * @param array<string, mixed> $result Payload of an accepted call, empty otherwise
     * @param ?string $reason Refusal sentence for the log, null when accepted
     */
    private function __construct(
        public bool $accepted,
        public array $result,
        public ?string $reason,
    ) {
    }

    /**
     * @param array<string, mixed> $result Payload the Gateway returned
     * @return self Accepted answer
     */
    public static function accepted(array $result): self
    {
        return new self(true, $result, null);
    }

    /**
     * @param string $reason Refusal sentence for the agent log
     * @return self Refused answer
     */
    public static function refused(string $reason): self
    {
        return new self(false, [], $reason);
    }
}
