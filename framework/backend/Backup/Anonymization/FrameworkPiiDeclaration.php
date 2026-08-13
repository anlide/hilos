<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

/**
 * FrameworkPiiDeclaration - the framework's own PII classification.
 *
 * The framework ships tables of its own (identities, sessions, verifications, push
 * subscriptions, notifications, settings), and a project cannot be asked to classify
 * them: it did not write them, and the coverage gate would then fail on every project
 * that adopts a new framework table. So the framework declares its own rows here, and
 * {@see PiiRegistry::fromCatalog()} merges a project's declaration over the top - a
 * project row for the same table replaces the framework row whole, which is how an
 * installation with a different judgement about a framework table says so.
 *
 * The declaration is empty in this leaf on purpose. HIL-275 owns the mechanism only;
 * which framework table gets which strategy is a separate decision per table, and it
 * lands in HIL-585. Until then every framework table is uncovered, which the gate says
 * out loud on the first restore that needs anonymization - the intended failure mode.
 */
final class FrameworkPiiDeclaration
{
    /**
     * Returns the framework's PII rows in the declaration form a catalog uses.
     *
     * @return array<int, array<class-string|string, array<string, AnonymizationStrategy>|AnonymizationStrategy>>
     *     Table keys to their per-column strategies (or to {@see AnonymizationStrategy::PURGE}),
     *     keyed by connection index
     */
    public static function rows(): array
    {
        return [];
    }
}
