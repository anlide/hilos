<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Markdown\DocLinkRule;
use Hilos\Tests\CodeStyle\Markdown\DocRouteRule;
use Hilos\Tests\CodeStyle\Markdown\MarkdownSources;
use PHPUnit\Framework\TestCase;

/**
 * Runs the agent-doc rules over a toy tree with every case seeded on purpose and
 * pins the exact report — both what must be caught and what must stay silent.
 *
 * The guard test is green by design, so it proves nothing about whether the rules
 * still fire. This test is the only thing that does, and it fails on drift in
 * either direction: a seeded case gone unreported, or a legitimate one reported.
 *
 * The fixtures need no path exclusion, unlike the PHP ones: the live scan covers
 * the agent docs, and `framework/tests` is not among them.
 */
final class AgentDocFixtureTest extends TestCase
{
    /** The toy tree has top-level entries of its own; the rules must not be wired to the repository's. */
    private const array TOP_LEVEL_ENTRIES = ['catalog', 'doc', 'skill', 'root.md'];

    public function testRouteRuleReportsExactlyTheSeededCases(): void
    {
        $this->assertSame(
            [
                'DOC-ROUTE catalog/declined-no-reason.md — the line declining a route names no reason '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-ROUTE catalog/orphan.md — no skill wrapper routes to this file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-ROUTE catalog/routed-and-declined.md — a wrapper routes to this file, and it also '
                    . 'declines a route (see docs/agents/rule-authoring.md)',
            ],
            iterator_to_array((new DocRouteRule($this->fixtureSources(), 'catalog'))->check(), false),
            'Fixture report drifted: DOC-ROUTE either stopped catching a seeded case or started reporting a '
                . 'legitimate one.',
        );
    }

    public function testLinkRuleReportsExactlyTheSeededCases(): void
    {
        $this->assertSame(
            [
                'DOC-LINK skill/SKILL.md:36 — markdown link ../catalog/missing.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK skill/SKILL.md:39 — markdown link ../catalog/nowhere.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK skill/SKILL.md:24 — routed path root.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK skill/SKILL.md:37 — routed path catalog/ghost.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/guide.md:8 — markdown link ../catalog/missing.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
            ],
            iterator_to_array((new DocLinkRule($this->fixtureSources()))->check(), false),
            'Fixture report drifted: DOC-LINK either stopped catching a seeded case or started reporting a '
                . 'legitimate one.',
        );
    }

    /**
     * @return MarkdownSources The toy tree, read by the very same code the live scan uses
     */
    private function fixtureSources(): MarkdownSources
    {
        return new MarkdownSources(
            dirname(__DIR__, 2) . '/CodeStyle/Fixtures/AgentDocs',
            ['skill/SKILL.md'],
            ['catalog/*.md', 'doc/*.md'],
            self::TOP_LEVEL_ENTRIES,
        );
    }
}
