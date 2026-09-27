<?php

declare(strict_types=1);

namespace Hilos\Legal;

/** LegalDocumentStanding - immutable legal comparison data (HIL-498). */
final readonly class LegalDocumentStanding
{
    /**
     * @param LegalDocument $document Document judged
     * @param LegalStanding $standing Acceptance coverage
     * @param ?LegalRevision $held Latest declared revision the person accepted
     * @param ?string $deadline Earliest outstanding substantial deadline, or null
     */
    public function __construct(
        public LegalDocument $document,
        public LegalStanding $standing,
        public ?LegalRevision $held,
        public ?string $deadline,
    ) {
    }
}
