<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

/**
 * TruthSourceGrant - one registry entry: the rows an agent owns and what it may do with them.
 *
 * Width and operations sit in one record rather than in two arrays keyed by the same pair,
 * because they are always answered together: a refusal names both, and a registration that
 * revoked one store without the other would leave a right nobody wrote down.
 */
final readonly class TruthSourceGrant
{
    /**
     * @param TruthSourceKeys $keys Rows this grant covers
     * @param TruthSourceOperations $operations Operations this grant allows on them
     */
    public function __construct(
        public TruthSourceKeys $keys,
        public TruthSourceOperations $operations,
    ) {
    }

    /**
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @return bool True when this grant allows the operation
     */
    public function allows(TruthSourceOperation $operation): bool
    {
        return $this->operations->allows($operation);
    }
}
