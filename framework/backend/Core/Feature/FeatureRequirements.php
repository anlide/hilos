<?php

declare(strict_types=1);

namespace Hilos\Core\Feature;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * What a project must register itself for one declared feature to be complete.
 *
 * A framework feature is never entirely framework-owned: it needs a page in the project's
 * PAGES, an agent pair in its AGENTS, a table in its TABLES. Declaring the feature and then
 * skipping one of those leaves the half-activated state this whole registry exists to make
 * impossible, so every such obligation is written here, next to the feature that owns it,
 * and checked instead of remembered.
 *
 * The fields split by WHEN they can be checked, not by importance:
 * - the first group is visible in the facade constants alone, so the startup activation
 *   validator reads it before any layer is built and refuses to boot on a gap;
 * - the second group (`$requiredDbTables`, `$requiredCliCommands`, `$requiresPresenceSource`)
 *   is not, so a per-demo unit test reads it instead. Migrations in particular must stay out
 *   of the startup check: they are applied as a separate step, and gating boot on them would
 *   fail every process that starts before the migration run.
 */
final readonly class FeatureRequirements
{
    /**
     * @param list<class-string<AbstractPage>> $requiredPages Framework page base classes a project page must extend
     * @param list<string> $requiredAgents Agent types that must carry both a worker and a daemon class
     * @param list<class-string<TableDefinition>> $requiredTables Framework table classes expected among TABLES
     * @param array<class-string<AbstractPage>, ?class-string<TableDefinition>> $requiredPageTables Page
     *     base class to the table it must be bound to, or null when any binding satisfies it
     * @param ?string $requiredCatalogConstant Facade constant the project must point at its own catalog
     * @param list<HilosFeature> $requires Features this one is built on top of
     * @param list<string> $requiredDbTables SQL tables the feature reads and writes, named by their
     *     entity `_table` constant so a rename cannot leave the requirement behind (checked by the
     *     demo test)
     * @param list<string> $requiredCliCommands CLI command names the feature is driven by (checked by the demo test)
     * @param bool $requiresPresenceSource Whether a runtime collection must report user presence (checked by the demo test)
     */
    public function __construct(
        public array $requiredPages = [],
        public array $requiredAgents = [],
        public array $requiredTables = [],
        public array $requiredPageTables = [],
        public ?string $requiredCatalogConstant = null,
        public array $requires = [],
        public array $requiredDbTables = [],
        public array $requiredCliCommands = [],
        public bool $requiresPresenceSource = false,
    ) {
    }
}
