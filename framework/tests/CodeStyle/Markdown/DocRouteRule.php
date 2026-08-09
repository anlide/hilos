<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Markdown;

/**
 * Enforces rule-authoring.md: every file of the code-style catalog is reachable
 * from at least one skill wrapper, or says in itself why it needs no route.
 *
 * The rule judges the tree as a whole, not a file at a time: whether one catalog
 * file is routed can only be answered by reading every wrapper first. That is
 * why it implements no per-file rule interface.
 *
 * Reachability is the direct mention, not its transitive closure: a wrapper that
 * routes to another wrapper does not inherit its files. A route an agent has to
 * derive is not a route it will take.
 */
final class DocRouteRule
{
    public const string ID = 'DOC-ROUTE';

    /**
     * The catalog under the rule. It is the one directory with an owning
     * mechanism — the language wrappers — so requiring a route there holds
     * without exceptions; the rest of `docs/agents/` has no such owner.
     */
    public const string CATALOG = 'docs/agents/code-style';

    private const string DOC = 'docs/agents/rule-authoring.md';

    /**
     * The line a file carries when it needs no route by design. The reason after
     * the dash is the point of it: without one the line is a silent mute, and the
     * next reader learns nothing about why the file stands apart.
     */
    private const string DECLINED_PATTERN = '~^[ \t]*Routed from:[ \t]*none[ \t]*(?:—|--|-)?[ \t]*(?<reason>[^\n]*)$~m';

    /**
     * @param MarkdownSources $sources Files to read and the addressing conventions to read them by
     * @param string $catalog Directory whose files must be routed, relative to the scanned root
     */
    public function __construct(
        private readonly MarkdownSources $sources,
        private readonly string $catalog,
    ) {
    }

    /**
     * @return string Rule id
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return string Owning document
     */
    public function doc(): string
    {
        return self::DOC;
    }

    /**
     * Carries no line number: the complaint is about a line missing from another
     * file, and printing a zero under it would be noise.
     *
     * @return iterable<string> One line per catalog file that is unrouted, muted without a reason, or both routed
     *                          and declined
     */
    public function check(): iterable
    {
        $routed = $this->routedPaths();

        foreach ($this->sources->markdownIn($this->catalog) as $path) {
            $reason = $this->declinedReason($this->sources->read($path));

            if (in_array($path, $routed, true)) {
                if ($reason !== null) {
                    yield $this->describe($path, 'a wrapper routes to this file, and it also declines a route');
                }
                continue;
            }

            if ($reason === null) {
                yield $this->describe($path, 'no skill wrapper routes to this file');
                continue;
            }

            if ($reason === '') {
                yield $this->describe($path, 'the line declining a route names no reason');
            }
        }
    }

    /**
     * Reads every wrapper for the paths it mentions, in either live convention —
     * a markdown link and a backticked bare path are both routes, because both
     * are written today and requiring one would invent a formatting rule nobody
     * declared.
     *
     * A mentioned path is collected whether or not it exists: a route pointing at
     * a missing file is DOC-LINK's complaint, not this rule's.
     *
     * @return array<int, string> Every path mentioned by a wrapper, relative to the scanned root
     */
    private function routedPaths(): array
    {
        $routed = [];
        foreach ($this->sources->wrappers() as $wrapper) {
            $text = $this->sources->read($wrapper);

            foreach ($this->sources->linksByLine($text) as $targets) {
                foreach ($targets as $target) {
                    foreach ($this->sources->linkTargets($target, $wrapper) as $path) {
                        $routed[$path] = true;
                    }
                }
            }

            foreach ($this->sources->backtickedByLine($text) as $spans) {
                foreach ($spans as $span) {
                    $path = $this->sources->rootAnchoredTarget($span);
                    if ($path !== null) {
                        $routed[$path] = true;
                    }
                }
            }
        }

        return array_keys($routed);
    }

    /**
     * @param string $text Contents of a catalog file
     * @return string|null Reason the file gives for needing no route, or null when it gives no such line
     */
    private function declinedReason(string $text): ?string
    {
        $prose = $this->sources->prose($text);

        return preg_match(self::DECLINED_PATTERN, $prose, $found) === 1 ? trim($found['reason']) : null;
    }

    /**
     * @param string $relativePath Catalog file the complaint is about, relative to the scanned root
     * @param string $message Short human-readable essence of the hit
     * @return string Single reportable line
     */
    private function describe(string $relativePath, string $message): string
    {
        return sprintf('%s %s — %s (see %s)', self::ID, $relativePath, $message, self::DOC);
    }
}
