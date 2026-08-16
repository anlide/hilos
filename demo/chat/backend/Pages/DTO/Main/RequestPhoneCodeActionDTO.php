<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * RequestPhoneCodeActionDTO - DTO for a phone one-time-code request payload (HIL-492).
 *
 * Public (anonymous-reachable) submit that asks for a login code to be sent to a
 * phone over a chosen channel. The phone is trimmed here and normalized to E.164 by
 * the handler; the channel is the key of a {@see CodeChannel} the project registered,
 * and the handler refuses one that is not in the registry before anything is minted.
 *
 * The channel is required rather than defaulted to SMS. The surface always knows
 * which button was pressed - a primary one is still a channel - and a payload that
 * could omit it would make "which channel did this code go over" unanswerable for
 * exactly the requests that arrive malformed.
 */
final class RequestPhoneCodeActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a phone-code request DTO.
     *
     * @param string $phone Submitted phone number (trimmed)
     * @param string $channel Code channel key the person chose (see CodeChannel::name())
     */
    public function __construct(
        public readonly string $phone,
        public readonly string $channel,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::REQUEST_PHONE_CODE;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Request DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            phone: trim(self::requireString($data, 'phone')),
            channel: trim(self::requireString($data, 'channel')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{phone: string, channel: string} Request payload
     */
    public function toArray(): array
    {
        return [
            'phone' => $this->phone,
            'channel' => $this->channel,
        ];
    }
}
