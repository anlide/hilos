<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Person whose send pause is being ended. */
final class SendPauseEndCommandData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';

    /**
     * @param int $userId Person whose last accepted send is aged
     * @throws InvalidFormatException When the id is not positive
     */
    public function __construct(public readonly int $userId)
    {
        if ($userId <= 0) {
            throw new InvalidFormatException('Ending a send pause needs a positive userId');
        }
    }

    /** @return array{userId: int} Command payload */
    public function toArray(): array
    {
        return [self::userId => $this->userId];
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Strictly parsed person
     * @throws InvalidFormatException When the id is absent, mistyped or not positive
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::userId));
    }
}
