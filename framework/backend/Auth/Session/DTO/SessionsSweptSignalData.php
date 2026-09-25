<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Sessions library → project agent: browser session rows removed by the sweep.
 */
final class SessionsSweptSignalData extends BaseDTO implements SignalDataInterface
{
    public const string sessionTokens = 'sessionTokens';

    /**
     * @param list<string> $sessionTokens Tokens of the removed session rows
     */
    public function __construct(public readonly array $sessionTokens)
    {
    }

    /**
     * @return array{sessionTokens: list<string>} DTO payload for transport
     */
    public function toArray(): array
    {
        return [self::sessionTokens => $this->sessionTokens];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no token list
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionTokens: self::optionalStringList($data, self::sessionTokens)
                ?? throw new InvalidFormatException('Payload carries no string list under key ' . self::sessionTokens),
        );
    }
}
