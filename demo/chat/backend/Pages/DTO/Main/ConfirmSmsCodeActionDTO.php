<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * ConfirmSmsCodeActionDTO - DTO for submitting an SMS one-time login code (HIL-280).
 *
 * Public (anonymous-reachable) submit that carries the phone and the delivered
 * code. The phone is trimmed here and normalized to E.164 by the handler; the
 * code is trimmed so surrounding whitespace never fails an otherwise valid code.
 */
final class ConfirmSmsCodeActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates an SMS-code confirmation DTO.
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
        return ChatSignalConstants::CONFIRM_SMS_CODE;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     */
    public static function fromArray(array $data): static
    {
        $phone = $data['phone'] ?? null;
        $code = $data['code'] ?? null;

        return new static(
            phone: is_string($phone) ? trim($phone) : '',
            code: is_string($code) ? trim($code) : '',
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
            'phone' => $this->phone,
            'code' => $this->code,
        ];
    }
}
