<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * RequestRegisterConfirmActionDTO - DTO for re-sending a pending registration's code.
 *
 * Public (anonymous-reachable) resend submit (HIL-415). The address arrives from the
 * client, as it does on every other code path here, because the session asking is
 * anonymous by definition - no account exists yet for it to be resolved from. It is
 * trimmed here and lowercased by the handler.
 *
 * The action name is older than this meaning: it used to request a confirmation
 * code for a signed-in user's own email. That flow is gone - an address is proven
 * before the account exists now - and the name was reused rather than retired,
 * since nothing but this surface ever called it.
 */
final class RequestRegisterConfirmActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a resend DTO.
     *
     * @param string $email Address whose pending registration should be re-sent (trimmed)
     */
    public function __construct(
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
        return HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM;
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
            email: trim(self::requireString($data, 'email')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string} Resend payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
        ];
    }
}
