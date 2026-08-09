<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Markdown\DocLinkRule;
use Hilos\Tests\CodeStyle\Markdown\DocRouteRule;
use Hilos\Tests\CodeStyle\Markdown\MarkdownSources;
use PHPUnit\Framework\TestCase;

/**
 * Runs the agent-doc rules over the repository's own markdown and fails on an
 * unreachable rule file or a reference that leads nowhere.
 *
 * There is no baseline behind this guard, unlike the PHP code-style family. Both
 * halves land green, and a debt list would be read as permission to leave the
 * next rule file unrouted — which is the very thing being closed.
 *
 * One test method per rule, so two unrelated failures do not arrive as one.
 */
final class AgentDocGuardTest extends TestCase
{
    public function testEveryCodeStyleRuleFileIsRoutedFromAWrapper(): void
    {
        $rule = new DocRouteRule(MarkdownSources::forRepository($this->repositoryRoot()), DocRouteRule::CATALOG);

        $this->assertSame(
            [],
            iterator_to_array($rule->check(), false),
            'A rule file no wrapper routes to is canonical, correct, and never read. Route it from the wrapper'
                . ' that owns its subject, or — when it needs no route by design — say so in the file itself with'
                . ' the refusal line described in ' . $rule->doc() . ':',
        );
    }

    public function testEveryLocalReferenceInTheAgentDocsResolves(): void
    {
        $rule = new DocLinkRule(MarkdownSources::forRepository($this->repositoryRoot()));

        $this->assertSame(
            [],
            iterator_to_array($rule->check(), false),
            'A reference that leads nowhere breaks the route as surely as a missing one. Fix the target, or drop'
                . ' the reference:',
        );
    }

    /**
     * @return string Absolute path of the repository root
     */
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
