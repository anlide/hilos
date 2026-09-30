<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Core\Daemon\DaemonApplication;
use Hilos\Database\Exception\UndeclaredDatabaseGuaranteeException;
use Hilos\Hilos;

/**
 * DatabaseGuaranteeStartupGuard - the question "what does your database promise" asked at the
 * start of a node (HIL-1206).
 *
 * A project owes both {@see DatabaseGuarantee} cases in {@see Hilos::DATABASE_GUARANTEES}, a single
 * node as much as a cluster: the facade describes the project, not one installation of it, and the
 * processes of one node already read back what the others wrote. The guard reads that constant and
 * nothing else - no query, no schema - so it answers before anything is composed.
 *
 * Runs from {@see DaemonApplication::run()} among the constant-only guards, ahead of the
 * anonymization gate that asks the live schema. Only the daemon carries it: a CLI process and the
 * framework's test facades call {@see Hilos::init()} too, and "the node does not start" is an
 * answer to the start of a node.
 */
final class DatabaseGuaranteeStartupGuard
{
    /**
     * Refuses the start of a node whose project does not state both database promises.
     *
     * @param class-string<Hilos> $hilosClass Project facade whose declaration is judged
     * @throws UndeclaredDatabaseGuaranteeException When the facade leaves any promise out, naming every missing one
     */
    public static function assertDeclared(string $hilosClass): void
    {
        $declared = Hilos::databaseGuaranteesOf($hilosClass);
        $missing = array_filter(
            DatabaseGuarantee::cases(),
            static fn (DatabaseGuarantee $guarantee): bool => !in_array($guarantee, $declared, true),
        );
        if ($missing === []) {
            return;
        }

        $message = "{$hilosClass} does not declare what its database guarantees, and every node relies on it"
            . ' (docs/agents/app-topology.md, Database Guarantees). Add to DATABASE_GUARANTEES once the database keeps it:';
        foreach ($missing as $guarantee) {
            $message .= "\n- DatabaseGuarantee::{$guarantee->name}: {$guarantee->obligation()}";
        }

        throw new UndeclaredDatabaseGuaranteeException($message);
    }
}
