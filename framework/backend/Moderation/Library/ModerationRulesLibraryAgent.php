<?php

declare(strict_types=1);

namespace Hilos\Moderation\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\ModerationRules;

/**
 * ModerationRulesLibraryAgent - the one writer of administrators' own moderation rules (HIL-1624).
 *
 * Owns `hilos_moderation_rule` whole: one holder for the whole cluster, placed by the policy, and
 * nobody per kind or per rule. A rule belongs to the installation rather than to a person or an
 * event, so there is no set to cut the table by, and the kind is a column of the row, not an owner
 * of it. Reading does not come here: a reader declares the table among its reads and asks
 * {@see ModerationRules::effectiveForKind()}, which answers from the database without waking the
 * holder (docs/agents/architecture/entity-libraries.md, "Reading Without The Owner").
 *
 * Concrete and registered without a project subclass, as the settings and i18n libraries are: the
 * table, its ORM and the kind directory are the framework's, and nothing here waits for a project
 * hook. It holds the claim and nothing else yet; the asks that make it write arrive with the
 * dialogs of the Rules page (HIL-1632, HIL-1633, HIL-1634).
 */
final class ModerationRulesLibraryAgent extends AbstractHilosAgent
{
    /**
     * The rules table, whole and for every operation.
     *
     * Claimed outright rather than by set or by key: adding a rule, editing its text and removing
     * it all arrive here, whatever the kind.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::moderationRules => TruthSourceOperation::ALL,
    ];

    public const string AGENT_TYPE = HilosAgentType::HILOS_MODERATION_RULES_LIBRARY;
}
