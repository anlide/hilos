<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Users library to session holder: end the other sessions after a password change (HIL-300). */
final class AuthOtherSessionsEndSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $userId Person whose other sessions end
     * @param string $sessionToken Acting session to preserve
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $sessionToken,
    ) {
    }

    /** @return array{userId: int, sessionToken: string} Frame payload */
    public function toArray(): array
    {
        return ['userId' => $this->userId, 'sessionToken' => $this->sessionToken];
    }

    /**
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws InvalidFormatException When the person or acting session is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, 'userId'), self::requireString($data, 'sessionToken'));
    }
}
