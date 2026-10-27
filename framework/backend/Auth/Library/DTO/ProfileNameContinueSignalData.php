<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Session holder → users library: continue only the addressed attempt.
 *
 */
final class ProfileNameContinueSignalData extends BaseDTO implements SignalDataInterface
{
    public const string SESSION_TOKEN_HASH = 'sessionTokenHash';
    public const string REQUEST_ID = 'requestId';

    /**
     * @param string $sessionTokenHash Hash addressing the session's attempt
     * @param string $requestId Accepted attempt identifier
     */
    public function __construct(
        public readonly string $sessionTokenHash,
        public readonly string $requestId,
    ) {
    }

    /** @return array<string, mixed> Internal signal payload */
    public function toArray(): array
    {
        return [self::SESSION_TOKEN_HASH => $this->sessionTokenHash, self::REQUEST_ID => $this->requestId];
    }

    /**
     * @param array<string, mixed> $data Internal signal payload
     * @return static Validated payload shape
     * @throws InvalidFormatException When a required field is absent or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::SESSION_TOKEN_HASH),
            self::requireString($data, self::REQUEST_ID),
        );
    }
}
