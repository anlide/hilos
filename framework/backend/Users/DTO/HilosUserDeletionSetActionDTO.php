<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Hilos user card request to change scheduled account deletion. */
final class HilosUserDeletionSetActionDTO extends ActionPayloadDTO
{
    public const string userId = 'userId';
    public const string scheduled = 'scheduled';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $userId Target account id
     * @param bool $scheduled Requested state
     * @throws InvalidFormatException When the account id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $scheduled,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Raw or action-envelope payload
     * @return static Typed account lifecycle action
     * @throws InvalidFormatException When the id or requested state is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            userId: self::requireInt($inner, self::userId),
            scheduled: self::requireBool($inner, self::scheduled),
        );
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_USER_DELETION_SET;
    }

    /** @return array<string, int|bool> Target account and requested state */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::scheduled => $this->scheduled,
        ];
    }
}
