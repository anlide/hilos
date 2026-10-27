<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * A server-validated name request sent to the session holder; the cookie token stays internal.
 *
 */
final class ProfileNameBeginSignalData extends BaseDTO implements SignalDataInterface
{
    public const string SESSION_TOKEN = 'sessionToken';
    public const string USER_ID = 'userId';
    public const string NAME = 'name';
    public const string ORIGIN_ACCEPT_KEY = 'originAcceptKey';
    public const array SECRET_FIELDS = [self::SESSION_TOKEN];

    /**
     * @param string $sessionToken Internal session cookie token
     * @param int $userId Person whose name is being changed
     * @param string $name Validated requested name
     * @param string $originAcceptKey Connection that submitted the request
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly int $userId,
        public readonly string $name,
        public readonly string $originAcceptKey,
    ) {
    }

    /** @return array<string, mixed> Internal signal payload */
    public function toArray(): array
    {
        return [
            self::SESSION_TOKEN => $this->sessionToken,
            self::USER_ID => $this->userId,
            self::NAME => $this->name,
            self::ORIGIN_ACCEPT_KEY => $this->originAcceptKey,
        ];
    }

    /**
     * @param array<string, mixed> $data Internal signal payload
     * @return static Validated payload shape
     * @throws InvalidFormatException When a required field is absent or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::SESSION_TOKEN),
            self::requireInt($data, self::USER_ID),
            self::requireString($data, self::NAME),
            self::requireString($data, self::ORIGIN_ACCEPT_KEY),
        );
    }
}
