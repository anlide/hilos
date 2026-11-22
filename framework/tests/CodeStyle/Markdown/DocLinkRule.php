<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Markdown;

/**
 * Enforces rule-authoring.md: a local reference in the agent docs points at a
 * file that exists. A stale link breaks a route as surely as a missing one.
 *
 * What counts as a reference depends on the kind of the file that carries it. In
 * a skill wrapper every path is an address, backticked ones included, because the
 * file exists to be followed. In a document only a markdown link is: prose names
 * files inside other roots constantly, and reading those as addresses reported 46
 * legitimate mentions on the tree this was measured against.
 *
 * A fragment is part of the address: a link to `page.md#section` that names no
 * anchor of `page.md` breaks the jump as surely as a missing file, so the fragment
 * of a reference to a `.md` file is checked against that file's headings and
 * `<a id>`/`<a name>` anchors.
 *
 * The rule judges a file at a time, and reads text rather than tokens, so it
 * implements no interface the PHP rules share.
 */
final class DocLinkRule
{
    public const string ID = 'DOC-LINK';

    private const string DOC = 'docs/agents/rule-authoring.md';

    /**
     * @param MarkdownSources $sources Files to read and the addressing conventions to read them by
     */
    public function __construct(private readonly MarkdownSources $sources)
    {
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
     * Carries a line number, unlike DOC-ROUTE: a broken reference sits on a line
     * of its own, and naming it is what makes the hit fixable without a search.
     *
     * @return iterable<string> One line per reference whose target exists nowhere
     */
    public function check(): iterable
    {
        foreach ($this->sources->wrappers() as $relativePath) {
            yield from $this->checkRouter($relativePath);
        }

        foreach ($this->sources->documents() as $relativePath) {
            yield from $this->checkDocument($relativePath);
        }
    }

    /**
     * @param string $relativePath Path of the wrapper, relative to the scanned root
     * @return iterable<string> One line per broken link and per broken routed path
     */
    private function checkRouter(string $relativePath): iterable
    {
        $text = $this->sources->read($relativePath);

        yield from $this->brokenLinks($relativePath, $text);

        foreach ($this->sources->backtickedByLine($text) as $number => $spans) {
            foreach ($spans as $span) {
                $path = $this->sources->rootAnchoredTarget($span);
                if ($path === null) {
                    continue;
                }

                $error = $this->targetError($span, [$path]);
                if ($error !== null) {
                    yield $this->describe($relativePath, $number, sprintf('routed path %s %s', $span, $error));
                }
            }
        }
    }

    /**
     * @param string $relativePath Path of the document, relative to the scanned root
     * @return iterable<string> One line per broken link
     */
    private function checkDocument(string $relativePath): iterable
    {
        yield from $this->brokenLinks($relativePath, $this->sources->read($relativePath));
    }

    /**
     * @param string $relativePath Path of the file carrying the links, relative to the scanned root
     * @param string $text Contents of that file
     * @return iterable<string> One line per markdown link with a missing file or anchor
     */
    private function brokenLinks(string $relativePath, string $text): iterable
    {
        foreach ($this->sources->linksByLine($text) as $number => $targets) {
            foreach ($targets as $target) {
                $error = $this->targetError($target, $this->sources->linkTargets($target, $relativePath));
                if ($error !== null) {
                    yield $this->describe($relativePath, $number, sprintf('markdown link %s %s', $target, $error));
                }
            }
        }
    }

    /**
     * @param string $target Reference as written
     * @param array<int, string> $candidates Paths in resolution order, empty for references outside the rule
     * @return string|null Failure reason to append to the reference in a report
     */
    private function targetError(string $target, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (!$this->sources->exists($candidate)) {
                continue;
            }

            $fragment = $this->sources->fragment($target);
            if (
                $fragment !== null
                && $this->sources->isMarkdownFile($candidate)
                && !in_array($fragment, $this->sources->anchors($candidate), true)
            ) {
                return 'names no anchor in ' . $candidate;
            }

            return null;
        }

        return $candidates === [] ? null : 'resolves to no file';
    }

    /**
     * @param string $relativePath File the hit sits in, relative to the scanned root
     * @param int $line Line the hit sits on
     * @param string $message Short human-readable essence of the hit
     * @return string Single reportable line
     */
    private function describe(string $relativePath, int $line, string $message): string
    {
        return sprintf('%s %s:%d — %s (see %s)', self::ID, $relativePath, $line, $message, self::DOC);
    }
}
