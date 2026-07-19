<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * ConfirmRegisterActionDTO - DTO for submitting an email-confirmation code.
 *
 * Authenticated submit: carries only the code the signed-in user received; the
 * target email is resolved from the session, not the client. On success the
 * user's email identity is flipped to verified.
 */
final class ConfirmRegisterActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates an email-confirmation DTO.
     *
     * @param string $code Submitted verification code
     */
    public function __construct(
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
        return ChatSignalConstants::CONFIRM_REGISTER;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     */
    public static function fromArray(array $data): static
    {
        $code = $data['code'] ?? null;

        return new static(
            code: is_string($code) ? trim($code) : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{code: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
        ];
    }
}
