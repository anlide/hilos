<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\Library\ModerationRulesLibraryAgentDaemon;
use PHPUnit\Framework\TestCase;

/**
 * The ownership boundary of the moderation rules library (HIL-1624).
 *
 * What is pinned is who may write `hilos_moderation_rule` and how wide that claim is: the whole
 * table, every operation, from one class and through the declaration alone - not by kind, not by
 * rows, not by a set, and nothing on the runtime half. The daemon half pins that the claim lives
 * in one process of its own under the same type, so a second writer cannot appear beside it.
 */
final class ModerationRulesLibraryAgentTest extends TestCase
{
    public function testTheLibraryClaimsTheRulesTableWholeAndForEveryOperation(): void
    {
        $this->assertSame(
            [HilosDbContext::moderationRules => TruthSourceOperation::ALL],
            ModerationRulesLibraryAgent::OWNS_DB,
        );

        $claims = OwnershipDeclaration::dbCollectionsOf(ModerationRulesLibraryAgent::class);

        $this->assertSame([HilosDbContext::moderationRules], array_keys($claims));
        $this->assertTrue($claims[HilosDbContext::moderationRules]->isComplete());
    }

    /**
     * No owner per kind or per rule: the claim is never narrowed to keys or to a set, and the
     * library owns nothing in the runtime.
     */
    public function testTheClaimIsNeitherByRowsNorBySetNorInTheRuntime(): void
    {
        $this->assertSame([], OwnershipDeclaration::dbRowCollectionsOf(ModerationRulesLibraryAgent::class));
        $this->assertSame([], OwnershipDeclaration::dbSetCollectionsOf(ModerationRulesLibraryAgent::class));
        $this->assertSame([], OwnershipDeclaration::rtCollectionsOf(ModerationRulesLibraryAgent::class));
        $this->assertSame([], OwnershipDeclaration::rtRowCollectionsOf(ModerationRulesLibraryAgent::class));
        $this->assertSame([], OwnershipDeclaration::rtSetCollectionsOf(ModerationRulesLibraryAgent::class));
    }

    public function testAgentAndDaemonShareTheRulesLibraryType(): void
    {
        $this->assertSame('hilos_moderation_rules_library', HilosAgentType::HILOS_MODERATION_RULES_LIBRARY);
        $this->assertSame(HilosAgentType::HILOS_MODERATION_RULES_LIBRARY, ModerationRulesLibraryAgent::AGENT_TYPE);
        $this->assertSame(HilosAgentType::HILOS_MODERATION_RULES_LIBRARY, ModerationRulesLibraryAgentDaemon::AGENT_TYPE);
    }

    public function testTheDaemonIsAMonopolisticUnindexedSingleton(): void
    {
        $daemon = new ModerationRulesLibraryAgentDaemon();

        $this->assertTrue($daemon->requiresMonopolisticProcess());
        $this->assertNull($daemon->getIndex());
        $this->assertSame(HilosAgentType::HILOS_MODERATION_RULES_LIBRARY, $daemon->getType());
    }
}
