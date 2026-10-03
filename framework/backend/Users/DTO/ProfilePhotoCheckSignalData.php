<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Whether this connection is waiting on its profile photo check. */
final class ProfilePhotoCheckSignalData extends BaseDTO implements SignalDataInterface
{
    public const string checking = 'checking';

    /** @param bool $checking Whether the check is still in progress */
    public function __construct(public readonly bool $checking)
    {
    }

    /** @return array{checking: bool} Transport payload */
    public function toArray(): array
    {
        return [self::checking => $this->checking];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored signal
     * @throws InvalidFormatException When the flag is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireBool($data, self::checking));
    }
}
