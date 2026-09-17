<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * ComposedClause - one clause of a composed document, carrying both sides.
 *
 * The standard clause is always present; the deviation is there when the revision declares one
 * over it. Consumers pick the side they render: the full document reads the deviation's text
 * where present, a consent summary its statement and direction, the admin plate both at once.
 */
final readonly class ComposedClause
{
    /**
     * @param StandardClause $standard Clause of the adopted standard set
     * @param ?Deviation $deviation Project's deviation over it, or null when the standard stands
     */
    public function __construct(
        public StandardClause $standard,
        public ?Deviation $deviation,
    ) {
    }
}
