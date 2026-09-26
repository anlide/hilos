<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\AccountBlockReader;

/**
 * Block writer → sessions library: look at this person's block flag again (HIL-289).
 *
 * It names whom to look at and nothing else. Whether the person is blocked is read by the
 * library itself through {@see AccountBlockReader} at the moment the frame arrives, so the frame
 * cannot be wrong about it: a false frame finds nothing to do, a repeated one finds it done, and
 * a frame that overtook a later write reads that later write.
 */
final class AccountBlockChangedSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';

    /**
     * @param int $userId Person whose block flag was written
     * @throws InvalidFormatException When the id names no account (zero or negative)
     */
    public function __construct(public readonly int $userId)
    {
        if ($userId <= 0) {
            throw new InvalidFormatException('Payload key ' . self::userId . ' holds a value that is not a positive integer');
        }
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array{userId: int} DTO payload for transport
     */
    public function toArray(): array
    {
        return [self::userId => $this->userId];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no positive user id
     */
    public static function fromArray(array $data): static
    {
        return new static(userId: self::requireInt($data, self::userId));
    }
}
