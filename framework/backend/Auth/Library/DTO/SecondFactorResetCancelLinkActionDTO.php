<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * SecondFactorResetCancelLinkActionDTO - DTO for the "it was not me" link of a delayed removal (HIL-494).
 *
 * Works without signing in: the token is the whole of the proof, which is why the action is
 * throttled and why the token is compared by its hash.
 */
final class SecondFactorResetCancelLinkActionDTO extends ActionPayloadDTO
{
    /**
     * @param string $token Token the link carried (trimmed)
     */
    public function __construct(
        public readonly string $token,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static DTO instance
     * @throws InvalidFormatException When the token is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(token: trim(self::requireString($data, 'token')));
    }

    /**
     * Convert to array for transport.
     *
     * @return array{token: string} Payload
     */
    public function toArray(): array
    {
        return ['token' => $this->token];
    }
}
