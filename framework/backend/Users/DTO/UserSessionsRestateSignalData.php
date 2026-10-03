<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Users library → sessions library: refresh every open session of one person. */
final class UserSessionsRestateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';

    /** @param int $userId Person whose sessions need refreshed identity fields */
    public function __construct(public readonly int $userId)
    {
    }

    /** @return array{userId: int} Transport payload */
    public function toArray(): array
    {
        return [self::userId => $this->userId];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored restate request
     * @throws InvalidFormatException When the user id is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::userId));
    }
}
