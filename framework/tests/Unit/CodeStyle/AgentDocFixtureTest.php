<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Markdown\DocLinkRule;
use Hilos\Tests\CodeStyle\Markdown\DocRouteRule;
use Hilos\Tests\CodeStyle\Markdown\MarkdownSources;
use Hilos\Tests\CodeStyle\Markdown\SkillHeaderRule;
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
                'DOC-ROUTE catalog/nested/deeper/orphan.md — no skill wrapper routes to this file '
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

    public function testSkillHeaderRuleReportsExactlyTheSeededCases(): void
    {
        $this->assertSame(
            [
                'SKILL-HEADER headers/colon-space/SKILL.md:3 — an unquoted value carries ": ", which ends the '
                    . 'scalar and opens a mapping (see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/empty-description/SKILL.md:3 — description has an empty value '
                    . '(see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/empty-name/SKILL.md:2 — name has an empty value '
                    . '(see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/hash/SKILL.md:3 — an unquoted value carries " #", which opens a comment '
                    . '(see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/indicator/SKILL.md:3 — an unquoted value opens with the YAML indicator [ '
                    . '(see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/no-close/SKILL.md — the header is not closed by a --- line '
                    . '(see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/no-description/SKILL.md — the header carries no description key '
                    . '(see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/no-name/SKILL.md — the header carries no name key '
                    . '(see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/no-open/SKILL.md — the file does not open with a --- line '
                    . '(see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/stray-line/SKILL.md:4 — this line is neither a key: value pair nor the '
                    . 'body of a block scalar (see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/trailing-colon/SKILL.md:3 — an unquoted value ends with a colon '
                    . '(see docs/agents/rule-authoring.md)',
                'SKILL-HEADER headers/unclosed-quote/SKILL.md:3 — a quoted value is not closed on its line '
                    . '(see docs/agents/rule-authoring.md)',
            ],
            iterator_to_array((new SkillHeaderRule($this->headerSources()))->check(), false),
            'Fixture report drifted: SKILL-HEADER either stopped catching a seeded case or started reporting a '
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

    /**
     * Own pattern, so these SKILL.md files do not move the DOC-ROUTE / DOC-LINK pins.
     *
     * @return MarkdownSources Header fixtures only; documents are empty because this rule reads none
     */
    private function headerSources(): MarkdownSources
    {
        return new MarkdownSources(
            dirname(__DIR__, 2) . '/CodeStyle/Fixtures/AgentDocs',
            ['headers/*/SKILL.md'],
            [],
            self::TOP_LEVEL_ENTRIES,
        );
    }
}
