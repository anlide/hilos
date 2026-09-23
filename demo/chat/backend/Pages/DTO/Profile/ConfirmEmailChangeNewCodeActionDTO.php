<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * ConfirmEmailChangeNewCodeActionDTO - DTO for step 4 of the profile email change (HIL-299).
 *
 * Carries everything the change rests on: the current address's code proven on step 2,
 * the new address, and the code that address received. The handler re-checks the address,
 * re-checks the first code, spends the second, then the first, and only then moves the
 * account. All three are trimmed here; the address is lowercased by the handler.
 */
final class ConfirmEmailChangeNewCodeActionDTO extends ChatActionPayloadDTO
{
    public const string CURRENT_CODE = 'currentCode';
    public const string EMAIL = 'email';
    public const string CODE = 'code';

    /**
     * Creates a new-address confirm DTO.
     *
     * @param string $currentCode Code of the current address proven on step 2 (trimmed)
     * @param string $email Submitted new email address (trimmed)
     * @param string $code Code the new address received (trimmed)
     */
    public function __construct(
        public readonly string $currentCode,
        public readonly string $email,
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
        return ChatSignalConstants::CHANGE_EMAIL_NEW_CONFIRM;
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
            currentCode: trim(self::requireString($data, self::CURRENT_CODE)),
            email: trim(self::requireString($data, self::EMAIL)),
            code: trim(self::requireString($data, self::CODE)),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{currentCode: string, email: string, code: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            self::CURRENT_CODE => $this->currentCode,
            self::EMAIL => $this->email,
            self::CODE => $this->code,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty proof, email, and code).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->currentCode !== '' && $this->email !== '' && $this->code !== '';
    }
}
