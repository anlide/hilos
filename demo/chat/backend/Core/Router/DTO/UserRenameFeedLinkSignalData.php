<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Users library → the person's agent: link a rename to its existing feed event. Not answered (HIL-1896). */
final class UserRenameFeedLinkSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string renameId = 'renameId';
    public const string eventId = 'eventId';

    /**
     * @param int $userId Person whose journal is written, and the agent index
     * @param int $renameId Captured journal row
     * @param int $eventId Feed event already written by the library
     * @throws InvalidFormatException When any id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $renameId,
        public readonly int $eventId,
    ) {
        if ($userId <= 0 || $renameId <= 0 || $eventId <= 0) {
            throw new InvalidFormatException('User, rename and event ids must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Feed link request
     * @throws InvalidFormatException When an id is absent, mistyped or not positive
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            renameId: self::requireInt($data, self::renameId),
            eventId: self::requireInt($data, self::eventId),
        );
    }

    /** @return array<string, int> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::renameId => $this->renameId,
            self::eventId => $this->eventId,
        ];
    }
}
