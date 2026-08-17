<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Database\Migration;

/**
 * RestoreMigrationLevelResult - what one restored connection's `migration` table is to say.
 *
 * The companion of {@see RestoreMigrationDecisionResult} and deliberately not the same thing:
 * that one judges whether the archive may be restored into this code at all, this one names
 * the level the engine writes into the restored database before it migrates. A schema archive
 * carries no rows in `migration`, so without this the restore would leave the table empty and
 * {@see Migration::migrateUp()} would replay the whole history over a finished schema.
 *
 * A refusal is not an error condition of the resolver but its ordinary second outcome: an
 * archive that declares no level and an operator who named none is a restore nobody can carry
 * out correctly, and the engine turns the refusal into a failure before the first import.
 */
final readonly class RestoreMigrationLevelResult
{
    /**
     * @param ?int $level Level to record before migrating; null when the connection is refused
     * @param ?string $reason Operator-facing refusal, recipe included; null when allowed
     */
    private function __construct(
        public ?int $level,
        public ?string $reason,
    ) {
    }

    /**
     * @param int $level Level the restored connection is to record before migrating
     * @return static Allowing result carrying that level
     */
    public static function allow(int $level): static
    {
        return new static($level, null);
    }

    /**
     * @param string $reason Operator-facing refusal, naming the connection and what to do
     * @return static Refusing result carrying that reason
     */
    public static function refuse(string $reason): static
    {
        return new static(null, $reason);
    }
}
