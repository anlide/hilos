<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Registration\RegistrationConsent;
use Hilos\Core\Exception\ValidationException;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * RequestMagicLinkActionDTO - DTO for an email magic-link request payload (HIL-283).
 *
 * Public (anonymous-reachable) submit that asks for a passwordless sign-in link to
 * be sent to an email. The email is trimmed here and lowercased by the handler
 * before the token is issued; the response is always the same generic success, so
 * this payload never reveals whether the address has an account.
 */
final class RequestMagicLinkActionDTO extends ActionPayloadDTO
{
    /**
     * Creates a magic-link request DTO.
     *
     * @param string $email Submitted account email (trimmed)
     * @param ?array<string, string> $acceptedRevisions Accepted document-to-revision boundary map, or null for a repeat
     */
    public function __construct(
        public readonly string $email,
        public readonly ?array $acceptedRevisions = null,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Request DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     * @throws ValidationException When the accepted revision map has an unknown document or invalid revision id
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: trim(self::requireString($data, 'email')),
            acceptedRevisions: RegistrationConsent::readPayload(self::optionalArray($data, RegistrationConsent::PAYLOAD_KEY)),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Action payload including the optional accepted revisions
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            RegistrationConsent::PAYLOAD_KEY => $this->acceptedRevisions,
        ];
    }
}
