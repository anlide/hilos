<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Registration\RegistrationThemePick;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ConfirmMagicLinkCodeActionDTO - DTO for submitting the code from a magic-link letter (HIL-606).
 *
 * Public (anonymous-reachable) submit from the waiting screen itself, not from the
 * /auth/magic route: the person has the letter open elsewhere and types the six digits
 * where they already stand. It carries the target email and that code. The email is
 * trimmed here and lowercased by the handler; the code is trimmed so a value pasted with
 * surrounding whitespace never fails an otherwise valid attempt.
 *
 * Separate from {@see ConfirmMagicLinkActionDTO} because the secrets are separate
 * challenges with separate attempt ceilings - naming the field `code` rather than `token`
 * is what tells the handler which half it was handed.
 * The guest's theme pick is written into the account if this submit creates one (HIL-1427).
 */
final class ConfirmMagicLinkCodeActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = ['code'];

    /**
     * Creates a magic-link code confirmation DTO.
     *
     * @param string $email Submitted account email (trimmed)
     * @param string $code Submitted companion sign-in code (trimmed)
     * @param ?string $themePick Guest browser's choice, or null when they never picked
     */
    public function __construct(
        public readonly string $email,
        public readonly string $code,
        public readonly ?string $themePick = null,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     * @throws InvalidFormatException When a required field is absent or a submitted field is not a string
     * @throws ValidationException When the theme choice is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: trim(self::requireString($data, 'email')),
            code: trim(self::requireString($data, 'code')),
            themePick: RegistrationThemePick::readPayload(self::optionalString($data, RegistrationThemePick::PAYLOAD_KEY)),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string, code: string, themePick: ?string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'code' => $this->code,
            RegistrationThemePick::PAYLOAD_KEY => $this->themePick,
        ];
    }
}
