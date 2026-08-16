<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * RestoreMigrationDecisionResult - the migration gate's answer for one archive.
 *
 * A bare {@see RestoreMigrationDecision} tells the operator nothing actionable: a
 * refusal must name the connections that are ahead and by how much, and an allow must
 * still be able to announce the migrations the restore is about to apply. The result
 * carries the verdict, the refusal text, the level the code expects, and the raw gaps.
 *
 * It also words those gaps, because the CLI preflight and the backup page are two
 * presentations of one verdict and have no right to describe it differently. The wording
 * started inside the restore command and moved here the moment a second caller appeared.
 */
final readonly class RestoreMigrationDecisionResult
{
    /**
     * @param RestoreMigrationDecision $decision What the gate allows
     * @param ?string $reason Operator-facing explanation; null unless the restore is refused
     * @param ?int $codeIndex Migration level this code expects; null when it lists no migrations
     * @param list<RestoreMigrationGap> $gaps Connections whose level differs from the code's
     */
    public function __construct(
        public RestoreMigrationDecision $decision,
        public ?string $reason = null,
        public ?int $codeIndex = null,
        public array $gaps = [],
    ) {
    }

    /**
     * Words every gap for the operator, one line per connection.
     *
     * The lines are worded for an ALLOWED verdict - an archive behind the code, or one whose
     * level cannot be compared. A refusal names itself in {@see $reason} instead, with the
     * connections that are ahead and by how much, so neither caller words a refusal from here.
     *
     * @return list<string> One operator-facing line per gap; empty when every level matched
     */
    public function describeGaps(): array
    {
        $lines = [];
        foreach ($this->gaps as $gap) {
            $lines[] = self::describeGap($gap, $this->codeIndex);
        }

        return $lines;
    }

    /**
     * Reports how far behind the code the furthest lagging connection is.
     *
     * The largest gap and not their sum: the number answers "how much schema work follows
     * this restore", and the migrations are applied per connection, not once across them.
     * Connections that are level, ahead, or not comparable contribute nothing.
     *
     * @return ?int Migrations the furthest lagging connection will apply; null when none lags
     */
    public function migrationsBehind(): ?int
    {
        if ($this->codeIndex === null) {
            return null;
        }

        $behind = null;
        foreach ($this->gaps as $gap) {
            if ($gap->archiveIndex === null || $gap->archiveIndex >= $this->codeIndex) {
                continue;
            }
            $behind = max($behind ?? 0, $this->codeIndex - $gap->archiveIndex);
        }

        return $behind;
    }

    /**
     * @param RestoreMigrationGap $gap Connection whose level differs from the code's
     * @param ?int $codeIndex Migration level this code expects; null when it lists no migrations
     * @return string One operator-facing line
     */
    private static function describeGap(RestoreMigrationGap $gap, ?int $codeIndex): string
    {
        if ($gap->archiveIndex === null) {
            return "connection {$gap->connectionIndex}: archive records no migration level"
                . ' (sidecar predates the field); restoring without the compatibility check';
        }
        if ($codeIndex === null) {
            return "connection {$gap->connectionIndex}: archive at migration {$gap->archiveIndex},"
                . ' this installation lists no migrations; restoring without the compatibility check';
        }

        return sprintf(
            'connection %d: archive at migration %d, code expects %d;'
            . ' %d migration(s) will be applied after the import',
            $gap->connectionIndex,
            $gap->archiveIndex,
            $codeIndex,
            $codeIndex - $gap->archiveIndex,
        );
    }
}
