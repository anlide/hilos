<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * RequestEmailChangeNewCodeActionDTO - DTO for step 3 of the profile email change (HIL-299).
 *
 * Carries the new address and, beside it, the code of the current address proven on the
 * step before. The server holds no state between the steps, so that unspent code IS the
 * proof that this modal already answered for the current mailbox. Both are trimmed here;
 * the address is lowercased and format-checked by the handler.
 */
final class RequestEmailChangeNewCodeActionDTO extends ChatActionPayloadDTO
{
    public const string CURRENT_CODE = 'currentCode';
    public const string EMAIL = 'email';

    /**
     * Creates a new-address request DTO.
     *
     * @param string $currentCode Code of the current address proven on step 2 (trimmed)
     * @param string $email Submitted new email address (trimmed)
     */
    public function __construct(
        public readonly string $currentCode,
        public readonly string $email,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::CHANGE_EMAIL_NEW_REQUEST;
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
            currentCode: trim(self::requireString($data, self::CURRENT_CODE)),
            email: trim(self::requireString($data, self::EMAIL)),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{currentCode: string, email: string} Request payload
     */
    public function toArray(): array
    {
        return [
            self::CURRENT_CODE => $this->currentCode,
            self::EMAIL => $this->email,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty proof and email).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->currentCode !== '' && $this->email !== '';
    }
}
