<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** The person's agent → ChatAgent: outcome of taking this person's journal off the feed (HIL-1897). */
final class UserRenameFeedUnlinkedSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string clearId = 'clearId';
    public const string unlinked = 'unlinked';

    /**
     * @param int $userId Person whose journal is written, and the agent index
     * @param string $clearId History-clear attempt correlation
     * @param bool $unlinked Whether all links were removed
     * @throws InvalidFormatException When the person id is not positive or the correlation is empty
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $clearId,
        public readonly bool $unlinked,
    ) {
        if ($userId <= 0 || $clearId === '') {
            throw new InvalidFormatException('User id must be positive and clear id must not be empty');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static History-clear answer
     * @throws InvalidFormatException When a required field is absent, mistyped or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            clearId: self::requireString($data, self::clearId),
            unlinked: self::requireBool($data, self::unlinked),
        );
    }

    /** @return array<string, int|string|bool> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::clearId => $this->clearId,
            self::unlinked => $this->unlinked,
        ];
    }
}
