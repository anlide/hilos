<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** ChatAgent → the person's agent: take its journal off the feed before history is cleared (HIL-1897). */
final class UserRenameFeedUnlinkSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string clearId = 'clearId';

    /**
     * @param int $userId Person whose journal is written, and the agent index
     * @param string $clearId History-clear attempt correlation
     * @throws InvalidFormatException When the person id is not positive or the correlation is empty
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $clearId,
    ) {
        if ($userId <= 0 || $clearId === '') {
            throw new InvalidFormatException('User id must be positive and clear id must not be empty');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static History-clear request
     * @throws InvalidFormatException When a required field is absent, mistyped or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            clearId: self::requireString($data, self::clearId),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::clearId => $this->clearId,
        ];
    }
}
