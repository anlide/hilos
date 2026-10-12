<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Sessions holder → users library: release this browser's registration hold (HIL-1411). */
final class AuthRegistrationHoldReleaseSignalData extends BaseDTO implements SignalDataInterface
{
    public const string sessionToken = 'sessionToken';

    /**
     * @param string $sessionToken Session whose browser was forgotten
     */
    public function __construct(
        public readonly string $sessionToken,
    ) {
    }

    /** @return array<string, mixed> Payload for transport */
    public function toArray(): array
    {
        return [
            self::sessionToken => $this->sessionToken,
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
        );
    }
}
