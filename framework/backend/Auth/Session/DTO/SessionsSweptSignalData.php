<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Sessions library → project agent: browser session rows removed by the sweep.
 *
 * The two lists describe the same removed rows in the same order: the token at position N and the
 * row id at position N belong to one row. A project that keeps data per browser can drop it by token
 * or by the row id, which survives a rotation of the token.
 */
final class SessionsSweptSignalData extends BaseDTO implements SignalDataInterface
{
    public const string sessionTokens = 'sessionTokens';

    public const string sessionIds = 'sessionIds';

    /**
     * @param list<string> $sessionTokens Tokens of the removed session rows
     * @param list<int> $sessionIds Ids of the same removed rows, in the order of the tokens
     */
    public function __construct(public readonly array $sessionTokens, public readonly array $sessionIds)
    {
    }

    /**
     * @return array{sessionTokens: list<string>, sessionIds: list<int>} DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::sessionTokens => $this->sessionTokens,
            self::sessionIds => $this->sessionIds,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no token list or no list of row ids
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionTokens: self::optionalStringList($data, self::sessionTokens)
                ?? throw new InvalidFormatException('Payload carries no string list under key ' . self::sessionTokens),
            sessionIds: self::requireIdList($data, self::sessionIds),
        );
    }

    /**
     * Reads a list of integers the DTO cannot be built without.
     *
     * An entry that is not an integer refuses the whole list, as in {@see optionalStringList()}: a
     * numeric string is not an id the sender wrote as one.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the list
     * @return list<int> Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds anything but a list of integers
     */
    private static function requireIdList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidFormatException('Payload carries no list of integers under key ' . $key);
        }
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new InvalidFormatException('Payload carries no list of integers under key ' . $key);
            }
        }

        return $value;
    }
}
