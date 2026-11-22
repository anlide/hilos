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
                'DOC-LINK skill/SKILL.md:45 — markdown link ../doc/missing-link.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK skill/SKILL.md:24 — routed path root.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK skill/SKILL.md:37 — routed path catalog/ghost.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK skill/SKILL.md:41 — routed path doc/headings.md#absent names no anchor in doc/headings.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK skill/SKILL.md:46 — routed path doc/missing-real.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK skill/SKILL.md:56 — routed path doc/missing-outside.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:16 — markdown link headings.md#fenced-heading names no anchor in doc/headings.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:17 — markdown link headings.md#repeated-2 names no anchor in doc/headings.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:18 — markdown link headings.md#absent names no anchor in doc/headings.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:19 — markdown link #nowhere names no anchor in doc/anchors.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:24 — markdown link headings.md#array-keyguidemd names no anchor in doc/headings.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:27 — markdown link #absent names no anchor in doc/anchors.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:28 — markdown link #missing-code-label names no anchor in doc/anchors.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:34 — markdown link headings.md#openguidemd-see-appendix names no anchor in doc/headings.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:36 — markdown link missing-escaped.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:39 — markdown link missing-heading.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:42 — markdown link missing-paragraph.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:45 — markdown link missing-after.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:49 — markdown link missing_(draft_(v2)).md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:50 — markdown link missing.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:55 — markdown link headings.md#container-2 names no anchor in doc/headings.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:56 — markdown link headings.md#still-hidden names no anchor in doc/headings.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:57 — markdown link headings.md#literal names no anchor in doc/headings.md '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:60 — markdown link missing-item.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:63 — markdown link missing-quote.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:67 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:68 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:70 — markdown link missing-inner.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:71 — markdown link missing-array.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:74 — markdown link missing-before-comment.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:77 — markdown link missing-before-comment.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:82 — markdown link missing-after-marker.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:84 — markdown link missing-example.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:86 — markdown link missing-after-marker.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:90 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:92 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:96 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:98 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:98 — markdown link missing-surplus.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:100 — markdown link missing-before-comment.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:104 — markdown link missing-after-comment.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:108 — markdown link missing-after-empty-item.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:116 — markdown link missing-table.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:118 — markdown link missing-outside.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:118 — markdown link missing-last.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:122 — markdown link missing-table.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:124 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:132 — markdown link missing-after-comment.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:136 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:136 — markdown link missing-surplus.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:140 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:144 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:144 — markdown link missing-surplus.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:148 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:153 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:158 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:158 — markdown link missing-surplus.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:162 — markdown link missing-short-row.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/anchors.md:163 — markdown link missing-cell.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
                'DOC-LINK doc/guide.md:8 — markdown link ../catalog/missing.md resolves to no file '
                    . '(see docs/agents/rule-authoring.md)',
            ],
            iterator_to_array((new DocLinkRule($this->fixtureSources()))->check(), false),
            'Fixture report drifted: DOC-LINK either stopped catching a seeded case or started reporting a '
                . 'legitimate one.',
        );
    }

    public function testFencesBelongToTheirContainerAndCloseWithTheirOpeningRun(): void
    {
        $sources = $this->fixtureSources();
        foreach (['```', '~~~~', '```` suffix'] as $falseClose) {
            $sample = "````md\n$falseClose\n### Literal\n[Example](sample.md)\n````\n[Open](live.md)";
            $this->assertSame([6 => ['live.md']], $sources->linksByLine($sample), $falseClose);
            $this->assertSame(str_repeat("\n", 5) . '[Open](live.md)', $sources->prose($sample));
        }

        $this->assertSame([3 => ['live.md']], $sources->linksByLine("```[Sample](sample.md)```\n\n[Open](live.md)"));
        $this->assertSame([2 => ['live.md']], $sources->linksByLine("- A paragraph\n    [Open](live.md)"));
    }

    public function testInlineMarkupEndsAtItsMarkdownBlock(): void
    {
        $sources = $this->fixtureSources();
        $live = '[Open](live.md) `';
        foreach (['-', '+', '*', '1.', '2)', '  -'] as $marker) {
            $this->assertSame([2 => ['live.md']], $sources->linksByLine("- Tick `\n$marker $live"), $marker);
            $this->assertSame([], $sources->linksByLine("- [Half\n$marker tail](sample.md)"), $marker);
        }

        foreach ([
            "Outside `\n> $live" => 2,
            "> First `\n>\n> $live" => 3,
            "> - First `\n>   ## $live" => 2,
            "First `\n***\n$live" => 3,
            "First `\n===\n$live" => 3,
            "| A | B |\n|---|---|\n| Tick ` | $live |\n| `[Sample](sample.md)` | B |" => 3,
            "A | B\n---|---\nX | Tick `\n$live | Y" => 4,
            "A | B\n---|---\nA \\| B | [Open](live.md) | [Example](sample.md)" => 3,
            "An ordinary `phrase | [Sample](sample.md)` plus [Open](live.md)" => 1,
            "A | B\n---|---|---\nA `phrase | [Sample](sample.md)` plus [Open](live.md)" => 3,
            "- Item\n\n\t```md\n\t[Sample](sample.md)\n\t```\n[Open](live.md)" => 6,
            "- Starts `[Sample](sample.md)\n  ends here`, [Open](live.md)" => 2,
            "> Starts `[Sample](sample.md)\n> ends here`, [Open](live.md)" => 2,
            "> - Starts `[Sample](sample.md)\n> ends here`, [Open](live.md)" => 2,
            "> Starts `[Sample](sample.md)\n> 1.\n> ends here`, [Open](live.md)" => 3,
            "> > Starts `[Sample](sample.md)\n> +\n> > ends here`, [Open](live.md)" => 3,
            "> + Starts `[Open](live.md)\n> +\n>   ends here`" => 1,
            "- First `\n> $live" => 2,
            "> First `\n# $live" => 2,
            "> Starts `[Open](live.md)\n<!-- literal ` -->" => 1,
            "> > Starts `[Open](live.md)\n> <!-- literal ` [Sample](sample.md) -->" => 1,
            "- Starts `[Open](live.md)\n<div>literal `</div>" => 1,
            "> Starts `[Sample](sample.md)\n<em>ends here</em>`, [Open](live.md)" => 2,
            "- First `\n~~~md\n[Sample](sample.md)\n~~~\n$live" => 5,
            "> ~~~md\n> [Sample](sample.md)\n$live" => 3,
            "> [Open](\n> live.md)" => 2,
            "First `\n2. [Sample](sample.md)`, [Open](live.md)" => 2,
            '[Outer `[Inner](sample.md)`](live.md)' => 1,
            '[Outer [Inner](invalid path)](live.md)' => 1,
            '[Outer [Inner](live.md "](sample.md)")](outer.md)' => 1,
            '![Array [key]](live.md "See [Example](sample.md)")' => 1,
            '[![Preview](image.png)](live.md)' => 1,
        ] as $markdown => $line) {
            $this->assertSame([$line => ['live.md']], $sources->linksByLine($markdown), $markdown);
        }

        $comment = "> Starts `[Open](live.md)\n> <!-- literal ` -->";
        $this->assertSame($comment, $sources->prose($comment));
    }

    public function testCommentBodyRemainsAvailableToTheProseReader(): void
    {
        $sources = $this->fixtureSources();
        $comment = "> Starts `[Open](live.md)\n> <!-- literal `\n> [Sample](sample.md)\n> -->";
        $this->assertSame([1 => ['live.md']], $sources->linksByLine($comment));
        $this->assertSame($comment, $sources->prose($comment));

        $comment = "> > <!--\n> > - [Sample](sample.md)\n> > ~~~\n> [Outside](live.md)";
        $this->assertSame([4 => ['live.md']], $sources->linksByLine($comment));
        $this->assertSame($comment, $sources->prose($comment));
    }

    public function testEmptyItemContentStartsOneColumnAfterItsMarker(): void
    {
        $sources = $this->fixtureSources();
        foreach ([
            ['+ Previous', '+   ', '     ', '      '],
            ['  + Previous', '  +', '       ', '        '],
            ['9) Previous', '10)', '       ', '        '],
            ['9) Previous', '10)      ', '       ', '        '],
        ] as [$previous, $marker, $prose, $code]) {
            $start = "$previous\n$marker\n";
            $this->assertSame([3 => ['live.md']], $sources->linksByLine($start . $prose . '[Open](live.md)'));
            $this->assertSame([], $sources->linksByLine($start . $code . '[Sample](sample.md)'));
        }
    }

    public function testTableEndsWhenAnInnerQuoteEnds(): void
    {
        $markdown = "> > A | B\n> > ---|---\n> Outside\n> Text | [Open](live.md) | [Also open](last.md)";
        $this->assertSame([4 => ['live.md', 'last.md']], $this->fixtureSources()->linksByLine($markdown));
    }

    public function testIndentedCodeAfterATableKeepsItsLineInProse(): void
    {
        $sources = $this->fixtureSources();
        $header = "> | A | B |\n> |---|---|\n";
        $sample = '[Sample](sample.md)';
        $after = "\n> Text | [Open](live.md) | [Also open](last.md)";
        $shortRow = $header . '>    ' . $sample . $after;
        $this->assertSame($shortRow, $sources->prose($shortRow));
        $this->assertSame($header . $after, $sources->prose($header . '>     ' . $sample . $after));
    }

    public function testReflowedWrapperLabelCarriesItsOwnRoute(): void
    {
        $wrapper = "> [See\n> ``doc/route.md``](\n> guide.md \"see `doc/example.md`\")";
        $sources = $this->fixtureSources();
        $this->assertSame([2 => ['doc/route.md']], $sources->backtickedByLine($wrapper));
        $this->assertSame([3 => ['guide.md']], $sources->linksByLine($wrapper));
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
