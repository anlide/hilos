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
}
