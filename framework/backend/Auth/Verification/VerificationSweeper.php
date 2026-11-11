<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Helpers\TimeHelper;

/** Removes a bounded batch of old spent or expired verification rows. */
final class VerificationSweeper
{
    public const int BATCH = 500;

    /**
     * @return int Number of rows removed in this pass
     * @throws DbCollectionNotReadableException When the collection cannot be read here
     * @throws LogicException When the collection is not configured
     * @throws DatabaseException When settings or verification persistence fails
     * @throws EnvException When the send window is invalid
     * @throws SettingException When a stored setting is invalid
     */
    public function sweep(): int
    {
        $now = TimeHelper::nowMs();

        return $this->collection()->deleteSpentBefore(
            $now - VerificationSweepSettings::retentionSeconds() * TimeConstants::MS_PER_SECOND,
            $now,
            self::BATCH,
        );
    }

    /**
     * @param string $identifier Address whose challenge rows are aged for testing
     * @return int Number of aged rows
     * @throws DbCollectionNotReadableException When the collection cannot be read here
     * @throws LogicException When the collection is not configured
     * @throws DatabaseException When settings or verification persistence fails
     * @throws EnvException When the send window is invalid
     * @throws SettingException When a stored setting is invalid
     */
    public function age(string $identifier): int
    {
        return $this->collection()->backdateIdentifier(
            $identifier,
            TimeHelper::nowMs() - (VerificationSweepSettings::retentionSeconds() + 1) * TimeConstants::MS_PER_SECOND,
        );
    }

    /**
     * @param string $identifier Address to count
     * @return int Number of remaining rows
     * @throws DbCollectionNotReadableException When the collection cannot be read here
     * @throws LogicException When the collection is not configured
     */
    public function countFor(string $identifier): int
    {
        return $this->collection()->countForIdentifier($identifier);
    }

    /**
     * @return ObjectUserVerifications Persistence primitives of verification rows
     * @throws LogicException When the object collection is unavailable
     */
    private function collection(): ObjectUserVerifications
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::verifications);
        if (!$collection instanceof ObjectUserVerifications) {
            throw new LogicException('Verification object collection is not configured');
        }

        return $collection;
    }
}
