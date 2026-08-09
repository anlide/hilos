<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * RequestSmsCodeActionDTO - DTO for an SMS one-time-code request payload (HIL-280).
 *
 * Public (anonymous-reachable) submit that asks for a login code to be sent to a
 * phone. The phone is trimmed here and normalized to E.164 by the handler before
 * the code is issued; the response is always the same generic success, so this
 * payload never reveals whether the number has an account.
 */
final class RequestSmsCodeActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates an SMS-code request DTO.
     *
     * @param string $phone Submitted phone number (trimmed)
     */
    public function __construct(
        public readonly string $phone,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::REQUEST_SMS_CODE;
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
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{phone: string} Request payload
     */
    public function toArray(): array
    {
        return [
            'phone' => $this->phone,
        ];
    }
}
