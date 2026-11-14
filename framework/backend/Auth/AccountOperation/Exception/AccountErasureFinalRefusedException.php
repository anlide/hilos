<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Exception;

use Hilos\Auth\AccountOperation\AccountErasureFinal;
use Hilos\Auth\AccountOperation\AccountErasurePersonalFinal;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;

/**
 * The database refused the person's erasure final: a row of another table still references the
 * account, and the whole final rolled back (HIL-1942).
 *
 * Thrown by {@see AccountErasurePersonalFinal::run()} after the step's transaction rolled back on a
 * restricted delete. It keeps only the safe reason - the account and the name of the referencing
 * table, when the schema knows it - which the coordinator keeps on the operation
 * ({@see AccountErasureFinal::refused()}). The engine's own text is never part of the words; it stays
 * on the previous exception, for the log.
 * SCAFFOLD: no live caller until the erasure coordinator answers the refusal (HIL-1950), live at the switch (HIL-1952).
 */
final class AccountErasureFinalRefusedException extends ValidationException
{
    /**
     * @param int $userId Account whose final was refused
     * @param ?string $table Table still referencing the account, or null when the refusal named no known table
     * @param string $message Words of the refusal
     * @param ForeignKeyConstraintException $previous The database's refusal
     */
    private function __construct(
        public readonly int $userId,
        public readonly ?string $table,
        string $message,
        ForeignKeyConstraintException $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param int $userId Account whose final was refused
     * @param ?string $table Table still referencing the account, or null when the refusal named no known table
     * @param ForeignKeyConstraintException $previous The database's refusal, kept for the log
     * @return self The refusal, naming the table when there is one and never the engine's text
     */
    public static function of(int $userId, ?string $table, ForeignKeyConstraintException $previous): self
    {
        return new self(
            $userId,
            $table,
            $table === null
                ? 'The person\'s erasure final was refused: another table still references the account.'
                : "The person's erasure final was refused: table {$table} still references the account.",
            $previous,
        );
    }
}
