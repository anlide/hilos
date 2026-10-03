<?php

declare(strict_types=1);

namespace Hilos\Legal\Export\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** An erased account: every finished file of acceptance records may name it, and the one being built is restarted. */
final class LegalAcceptancesExportForgetSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';

    /**
     * @param int $userId Person whose account was erased
     */
    public function __construct(public readonly int $userId)
    {
    }

    /**
     * @return array{userId: int} Wire form
     */
    public function toArray(): array
    {
        return [self::userId => $this->userId];
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored payload
     * @throws InvalidFormatException When the user id is absent or not an integer
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::userId));
    }
}
