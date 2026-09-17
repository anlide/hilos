<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * Deviation - a project's replacement for one clause of the standard set.
 *
 * A deviation always names a clause of the set version its revision adopts and replaces that
 * clause's full text in place. A project cannot declare a clause of its own: a genuinely new
 * clause is a framework set version.
 */
final readonly class Deviation
{
    /**
     * @param string $clauseKey Key of the standard clause this deviation replaces
     * @param DeviationDirection $direction Which way the project departs from the standard
     * @param string $statement One-line human statement of the difference
     * @param string $textFile Absolute path of the file carrying the deviation's full text
     */
    public function __construct(
        public string $clauseKey,
        public DeviationDirection $direction,
        public string $statement,
        public string $textFile,
    ) {
    }
}
