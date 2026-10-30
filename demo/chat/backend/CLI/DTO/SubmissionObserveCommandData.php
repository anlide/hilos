<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Connection whose current submission is being observed. */
final class SubmissionObserveCommandData extends BaseDTO implements SignalDataInterface
{
    public const string acceptKey = 'acceptKey';

    /**
     * @param string $acceptKey Connection under observation
     * @throws InvalidFormatException When the coordinate is empty
     */
    public function __construct(public readonly string $acceptKey)
    {
        if ($acceptKey === '') {
            throw new InvalidFormatException('Submission observation needs a nonempty acceptKey');
        }
    }

    /** @return array{acceptKey: string} Command payload */
    public function toArray(): array
    {
        return [self::acceptKey => $this->acceptKey];
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Strictly parsed coordinate
     * @throws InvalidFormatException When the coordinate is absent, empty or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::acceptKey));
    }
}
