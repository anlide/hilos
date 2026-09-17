<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * StandardClause - one clause of a framework standard set.
 *
 * The key is what a project's deviation names, the statement is the one-line human form a
 * consent summary shows, and the text file carries the full text a public document renders.
 * A clause unchanged in a newer set version points at the same file.
 */
final readonly class StandardClause
{
    /**
     * @param string $key Clause key, unique within its set version (`standard.no_sale`)
     * @param string $statement One-line human statement of the clause
     * @param string $textFile Absolute path of the file carrying the clause's full text
     */
    public function __construct(
        public string $key,
        public string $statement,
        public string $textFile,
    ) {
    }
}
