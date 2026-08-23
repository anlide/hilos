<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
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
 */
final class ConfirmMagicLinkCodeActionDTO extends ActionPayloadDTO
{
    /**
     * Creates a magic-link code confirmation DTO.
     *
     * @param string $email Submitted account email (trimmed)
     * @param string $code Submitted companion sign-in code (trimmed)
     */
    public function __construct(
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
        return HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE;
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
            email: trim(self::requireString($data, 'email')),
            code: trim(self::requireString($data, 'code')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string, code: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'code' => $this->code,
        ];
    }
}
