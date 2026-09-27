<?php

declare(strict_types=1);

namespace Hilos\Legal;

/** LegalClauseChange - immutable legal comparison data (HIL-498). */
final readonly class LegalClauseChange
{
    /**
     * @param string $clauseKey Stable standard clause key
     * @param string $title Standard statement naming the clause
     * @param LegalChangeKind $kind Kind of change
     * @param ?LegalClauseSide $before Earlier side, absent for an addition
     * @param ?LegalClauseSide $after Later side, absent for a removal
     */
    public function __construct(
        public string $clauseKey,
        public string $title,
        public LegalChangeKind $kind,
        public ?LegalClauseSide $before,
        public ?LegalClauseSide $after,
    ) {
    }
}
