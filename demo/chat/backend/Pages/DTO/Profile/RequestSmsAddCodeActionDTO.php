<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * RequestSmsAddCodeActionDTO - DTO for the profile add-phone request payload (HIL-403).
 *
 * Step 1 of the add-an-SMS-identity wizard: an authenticated submit that asks for
 * a one-time code to be sent to a phone the signed-in user wants to attach. The
 * phone is trimmed here and normalized to E.164 by the handler before the code is
 * issued; the owning user is read from the session, never carried here.
 */
final class RequestSmsAddCodeActionDTO extends ChatActionPayloadDTO
{
    public const string PHONE = 'phone';

    /**
     * Creates an add-phone request DTO.
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
        return ChatSignalConstants::ADD_SMS_REQUEST;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Request DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            phone: is_string($data[self::PHONE] ?? null) ? trim($data[self::PHONE]) : '',
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
            self::PHONE => $this->phone,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty phone).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->phone !== '';
    }
}
