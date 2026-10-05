<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Users library to session holder: revoke a person's other browsers after a password change. */
final class AuthSecondFactorTrustRevokeOthersSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $userId Person whose other browsers lose trust
     * @param int $keepSessionId Current browser's session row
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $keepSessionId,
    ) {
    }

    /** @return array{userId: int, keepSessionId: int} Transport payload */
    public function toArray(): array
    {
        return ['userId' => $this->userId, 'keepSessionId' => $this->keepSessionId];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored frame
     * @throws InvalidFormatException When a required field is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, 'userId'),
            self::requireInt($data, 'keepSessionId'),
        );
    }
}
