<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\ValidationException;

/**
 * A checked account operation order supplied by the caller, with the root account last.
 * SCAFFOLD: durable operation storage will consume this value (HIL-1936).
 */
final readonly class AccountOperationCircle
{
    /**
     * @param int $rootUserId Positive root account id
     * @param non-empty-list<int> $circleUserIds Detached, checked account operation order
     */
    private function __construct(public int $rootUserId, private array $circleUserIds)
    {
    }

    /**
     * Checks the supplied order without computing merge relationships or reordering ids.
     *
     * @param array<array-key, mixed> $circleUserIds Serialized ordered account ids
     * @param int $rootUserId Root account that must appear last
     * @return self Checked immutable circle
     * @throws ValidationException When ids are not a nonempty unique positive integer list ending with the positive root
     */
    public static function fromArray(array $circleUserIds, int $rootUserId): self
    {
        if ($rootUserId <= 0 || $circleUserIds === [] || !array_is_list($circleUserIds)) {
            throw new ValidationException('The account operation circle requires a positive root and a nonempty ordered list of accounts.');
        }

        $seen = [];
        $checkedIds = [];
        foreach ($circleUserIds as $userId) {
            if (!is_int($userId) || $userId <= 0) {
                throw new ValidationException('Every account in the account operation circle must have a positive integer id.');
            }
            if (isset($seen[$userId])) {
                throw new ValidationException('Each account may appear only once in the account operation circle.');
            }
            $seen[$userId] = true;
            $checkedIds[] = $userId;
        }
        if ($checkedIds[array_key_last($checkedIds)] !== $rootUserId) {
            throw new ValidationException('The root account must be last in the account operation circle.');
        }

        return new self($rootUserId, $checkedIds);
    }

    /** @return non-empty-list<int> Ordered account ids for serialization */
    public function toArray(): array
    {
        return $this->circleUserIds;
    }

    /**
     * @param int $userId Account id to test
     * @return bool Whether the account belongs to this circle
     */
    public function contains(int $userId): bool
    {
        return in_array($userId, $this->circleUserIds, true);
    }
}
