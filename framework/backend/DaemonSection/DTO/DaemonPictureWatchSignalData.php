<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Section viewer count: positive renews interest and zero cancels it. */
final class DaemonPictureWatchSignalData extends BaseDTO implements SignalDataInterface
{
    public const string viewers = 'viewers';

    public function __construct(public readonly int $viewers)
    {
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [self::viewers => $this->viewers];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed claim
     * @throws InvalidFormatException When viewer count is absent, invalid or negative
     */
    public static function fromArray(array $data): static
    {
        $viewers = self::requireInt($data, self::viewers);
        if ($viewers < 0) {
            throw new InvalidFormatException('Daemon picture watch carries a negative viewer count');
        }

        return new static($viewers);
    }
}
