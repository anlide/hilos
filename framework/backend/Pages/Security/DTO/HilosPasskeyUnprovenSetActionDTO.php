<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Auth\Method\PasskeyAddressPolicy;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the security_passkey_unproven_set action payload (HIL-1105).
 *
 * Says whether a passkey may be the only way into an account on an unconfirmed address
 * ({@see PasskeyAddressPolicy}). The value is the whole setting, so the flag crosses the wire
 * as it is: two administrators writing it at once leave the later one standing.
 */
final class HilosPasskeyUnprovenSetActionDTO extends ActionPayloadDTO
{
    /** Payload key: whether the setting should allow it. */
    public const string allowed = 'allowed';

    /**
     * @param bool $allowed Whether a passkey may start an account on an unconfirmed address
     */
    public function __construct(public readonly bool $allowed)
    {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::SECURITY_PASSKEY_UNPROVEN_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the flag is absent or not a boolean
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(allowed: self::requireBool($inner, self::allowed));
    }

    /**
     * @return array{allowed: bool} Data with the flag
     */
    public function toArray(): array
    {
        return [self::allowed => $this->allowed];
    }
}
