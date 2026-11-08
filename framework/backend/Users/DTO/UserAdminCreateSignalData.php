<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Sessions holder → users library: create an administrator for an anonymous session. */
final class UserAdminCreateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string correlationId = 'correlationId';
    public const string sessionToken = 'sessionToken';
    public const string expired = 'expired';

    /**
     * @param string $correlationId Parked command to answer
     * @param string $sessionToken Session to bind after creation
     * @param bool $expired Whether expiry dropped the session's previous person
     * @throws InvalidFormatException When the command or session address is empty
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $sessionToken,
        public readonly bool $expired,
    ) {
        if ($correlationId === '' || $sessionToken === '') {
            throw new InvalidFormatException('Admin creation needs a correlation id and session token');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Administrator creation request
     * @throws InvalidFormatException When a required field is absent, mistyped or empty
     */
    public static function fromArray(array $data): static
    {
        return new static(
            correlationId: self::requireString($data, self::correlationId),
            sessionToken: self::requireString($data, self::sessionToken),
            expired: self::requireBool($data, self::expired),
        );
    }

    /** @return array<string, string|bool> Transport payload */
    public function toArray(): array
    {
        return [
            self::correlationId => $this->correlationId,
            self::sessionToken => $this->sessionToken,
            self::expired => $this->expired,
        ];
    }
}
