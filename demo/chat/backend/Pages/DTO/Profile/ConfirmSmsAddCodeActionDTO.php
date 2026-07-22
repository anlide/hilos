<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * ConfirmSmsAddCodeActionDTO - DTO for the profile add-phone confirm payload (HIL-403).
 *
 * Step 2 of the add-an-SMS-identity wizard: an authenticated submit that carries
 * the phone and the delivered code. The phone is trimmed here and re-normalized to
 * E.164 by the handler; the code is trimmed so surrounding whitespace never fails
 * an otherwise valid code. The owning user is read from the session, never here.
 */
final class ConfirmSmsAddCodeActionDTO extends ChatActionPayloadDTO
{
    public const string PHONE = 'phone';
    public const string CODE = 'code';

    /**
     * Creates an add-phone confirm DTO.
     *
     * @param string $phone Submitted phone number (trimmed)
     * @param string $code Submitted verification code (trimmed)
     */
    public function __construct(
        public readonly string $phone,
        public readonly string $code,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::ADD_SMS_CONFIRM;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            phone: is_string($data[self::PHONE] ?? null) ? trim($data[self::PHONE]) : '',
            code: is_string($data[self::CODE] ?? null) ? trim($data[self::CODE]) : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{phone: string, code: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            self::PHONE => $this->phone,
            self::CODE => $this->code,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty phone and code).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->phone !== '' && $this->code !== '';
    }
}
