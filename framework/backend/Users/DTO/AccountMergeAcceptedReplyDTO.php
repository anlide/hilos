<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * The immediate answer to a browser merge: the operation was accepted.
 *
 * It is not the outcome of the operation and never becomes one. The outcome belongs to the
 * live account-card carrier; the operation id only names the accepted work.
 */
final class AccountMergeAcceptedReplyDTO extends ActionReplyDTO
{
    /** Wire key naming the accepted operation. */
    public const string operationId = 'operationId';

    /** @param int $operationId Positive id of the accepted merge */
    public function __construct(public readonly int $operationId)
    {
    }

    /** @return array<string, mixed> Accepted operation in its wire form */
    public function toArray(): array
    {
        return [self::operationId => $this->operationId];
    }

    /**
     * @param array<string, mixed> $data Accepted operation in its wire form
     * @return static Restored reply
     * @throws InvalidFormatException When the operation id is absent or is not a positive integer
     */
    public static function fromArray(array $data): static
    {
        $operationId = self::requireInt($data, self::operationId);
        if ($operationId <= 0) {
            throw new InvalidFormatException('Account-merge operation id must be positive.');
        }

        return new static($operationId);
    }
}
