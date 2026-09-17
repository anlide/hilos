<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * LegalRevision - one published revision of a project's legal document.
 *
 * A person holds a revision and the revision holds everything else: the set version it was built
 * on and its own deviations. That is why deviations hang here rather than on the document - an
 * old revision must keep composing to the text a person agreed to.
 *
 * `significance` and `effectiveOn` are declared whole with the record but read by nobody yet
 * beyond the one structural rule below, which {@see LegalCatalogResolver} enforces; what they
 * mean lands with HIL-498.
 */
final readonly class LegalRevision
{
    /**
     * @param LegalDocument $document Document this revision belongs to
     * @param string $id Revision id, its publication date as a string (`2026-07-28`)
     * @param string $publishedOn Publication date, `YYYY-MM-DD`
     * @param int $setVersion Version of the framework standard set this revision adopts
     * @param LegalSignificance $significance How much this revision changed against the previous one
     * @param string $effectiveOn Date the revision takes effect, `YYYY-MM-DD`
     * @param list<Deviation> $deviations Deviations from the adopted set, at most one per clause
     */
    public function __construct(
        public LegalDocument $document,
        public string $id,
        public string $publishedOn,
        public int $setVersion,
        public LegalSignificance $significance,
        public string $effectiveOn,
        public array $deviations,
    ) {
    }

    /**
     * Tells whether this revision lowers the significance of a set version it newly adopts.
     *
     * The one structural significance rule: a revision adopting a higher set version than its
     * predecessor may raise that set's significance, never lower it. A revision staying on its
     * predecessor's set version is not judged - there is no newly adopted set to fall below.
     *
     * @param self $predecessor Revision declared right before this one
     * @param StandardSet $adoptedSet Set version this revision adopts
     * @return bool True when the set version moved up and this revision declares less than that set
     */
    public function lowersAdoptedSetSignificance(self $predecessor, StandardSet $adoptedSet): bool
    {
        return $this->setVersion > $predecessor->setVersion && $this->significance->isBelow($adoptedSet->significance);
    }
}
