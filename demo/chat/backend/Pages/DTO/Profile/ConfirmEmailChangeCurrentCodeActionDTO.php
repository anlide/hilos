<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * ConfirmEmailChangeCurrentCodeActionDTO - DTO for step 2 of the profile email change (HIL-299).
 *
 * Carries the code mailed to the current address. The handler checks it without spending
 * it: the modal keeps it and sends it again with the next two steps, and it is spent only
 * when the address actually moves. The code is trimmed so surrounding whitespace never
 * fails an otherwise valid one.
 */
final class ConfirmEmailChangeCurrentCodeActionDTO extends ChatActionPayloadDTO
{
    public const string CODE = 'code';

    /**
     * Creates a current-address confirm DTO.
     *
     * @param string $code Submitted verification code (trimmed)
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
        return ChatSignalConstants::CHANGE_EMAIL_CURRENT_CONFIRM;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            code: trim(self::requireString($data, self::CODE)),
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
            self::CODE => $this->code,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty code).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->code !== '';
    }
}
