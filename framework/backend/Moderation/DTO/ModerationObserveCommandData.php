<?php

declare(strict_types=1);

namespace Hilos\Moderation\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Names exactly one connection and moderation kind for a next-tick observation. */
final class ModerationObserveCommandData extends BaseDTO implements SignalDataInterface
{
    public const string acceptKey = 'acceptKey';
    public const string kind = 'kind';

    /**
     * @param string $acceptKey Connection under observation
     * @param string $kind Moderation kind under observation
     * @throws InvalidFormatException When either coordinate is empty
     */
    public function __construct(public readonly string $acceptKey, public readonly string $kind)
    {
        if ($acceptKey === '' || $kind === '') {
            throw new InvalidFormatException('Moderation observation needs nonempty acceptKey and kind');
        }
    }

    /** @return array{acceptKey: string, kind: string} Command payload */
    public function toArray(): array
    {
        return [self::acceptKey => $this->acceptKey, self::kind => $this->kind];
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Strictly parsed coordinates
     * @throws InvalidFormatException When either coordinate is absent, empty or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::acceptKey), self::requireString($data, self::kind));
    }
}
