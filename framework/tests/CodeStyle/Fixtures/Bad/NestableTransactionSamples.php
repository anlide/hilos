<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Database\Database;

/**
 * Deliberately broken sample for NESTABLE-TRANSACTION: every call below marks a transaction
 * nestable, which the framework backend never does. Read under the root `framework/backend`
 * the rule reports each of the three calls; read under a demo's backend or under the
 * framework's own suite it reports nothing, and the declaration at the end is not a call
 * under any root.
 */
final class NestableTransactionSamples
{
    /**
     * @param ?Database $maybe Something that may hold a connection, or nothing
     */
    public function nest(?Database $maybe): void
    {
        Database::transactionStartNestable();
        $this->transactionStartNestable();
        $maybe?->transactionStartNestable();
    }

    /**
     * Wears the mark's name and starts nothing: a declaration, not a call.
     */
    public function transactionStartNestable(): void
    {
    }
}
