<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Users library → sessions holder: roll back only the session whose hold expired (HIL-1411). */
final class AuthRegistrationHoldExpiredSignalData extends BaseDTO implements SignalDataInterface
{
    public const string sessionToken = 'sessionToken';
    public const string identifier = 'identifier';

    /**
     * @param string $sessionToken Session whose registration hold expired
     * @param string $identifier Normalized identifier of the expired hold
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly string $identifier,
    ) {
    }

    /** @return array<string, mixed> Payload for transport */
    public function toArray(): array
    {
        return [
            self::sessionToken => $this->sessionToken,
            self::identifier => $this->identifier,
        ];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Parsed signal payload
     * @throws InvalidFormatException When a field is missing, mistyped or inconsistent
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionToken: self::requireString($data, self::sessionToken),
            identifier: self::requireString($data, self::identifier),
        );
    }
}
