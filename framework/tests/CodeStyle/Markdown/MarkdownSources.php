<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Markdown;

/**
 * Lists the markdown files the agent-doc rules read, tells a router from a
 * document, and turns the text of a reference into repository paths.
 *
 * The two kinds are told apart for one reason: a skill wrapper is a router, so
 * every path written in it is an address; a document describes structure, and a
 * bare path there is as often a file name inside someone else's root as it is a
 * link. Reading both the same way reported 46 legitimate mentions on the tree
 * this was measured against.
 */
final class MarkdownSources
{
    /** Routers: a path written in one of these is meant to be followed. */
    private const array WRAPPER_PATTERNS = ['skills/*/SKILL.md'];

    /** Documents: prose that may name a file without pointing at it. */
    private const array DOCUMENT_PATTERNS = [
        'agents.md',
        'CLAUDE.md',
        'docs/**/*.md',
        'demo/*/agents.md',
        'demo/*/spec/**/*.md',
    ];

    /**
     * Entries a repository-root-anchored path may start with. The list is written
     * out rather than read off the disk, because it is what separates an address
     * (`docs/agents/rule-authoring.md`) from a file name inside a package root
     * (`src/index.ts`), and that distinction must not move with the tree.
     *
     * The two root-level files earn their place: nineteen of the wrappers route
     * through a bare `agents.md`, and without it here the most referenced file in
     * the repository is the one address the rule cannot check.
     */
    private const array TOP_LEVEL_ENTRIES = [
        'docs',
        'skills',
        'framework',
        'demo',
        'scripts',
        '.cursor',
        'agents.md',
        'CLAUDE.md',
    ];

    /** `[text](target)`; the optional title after the target is dropped by the caller. */
    private const string LINK_PATTERN = '~!?\[[^]]*]\(([^)]*)\)~';

    /** A path written on its own, in backticks — the form a wrapper routes with. */
    private const string BACKTICKED_PATTERN = '~`([^`\n]+)`~';

    /** Anything carrying a scheme leaves the repository and is not ours to check. */
    private const string EXTERNAL_PATTERN = '~^[a-z][a-z0-9+.-]*:~i';

    /** Characters that make the text a shape — a glob or a placeholder — rather than a path. */
    private const string PLACEHOLDER_CHARACTERS = '*?<>{}$|"\'';

    /**
     * A backticked span with a space in it is a command or a phrase, not a path.
     * Without this, `scripts/hilos db:reset` in a wrapper reads as a route and is
     * reported broken — the false-positive direction this rule exists to avoid.
     */
    private const string WHITESPACE_PATTERN = '~\s~';

    /**
     * A line citation after the path. The docs cite sources this way constantly
     * (`skills/hilos-orm/SKILL.md:25`), single or as a range, and the line number
     * is not part of the address. Stripped before the scheme is looked for: a
     * name with a dot in it followed by a colon otherwise parses as a URI.
     */
    private const string CITATION_PATTERN = '~:\d+(?:-\d+)?$~';

    /**
     * A fence opening or closing a code block. Its body is a sample, not a
     * reference: a link shown in an example is not a link an agent follows, and a
     * path shown there is not a route.
     */
    private const string FENCE_PATTERN = '#^\s*(?:```+|~~~+)#';

    /** @var array<int, string>|null Wrapper paths, walked once per instance */
    private ?array $wrappers = null;

    /** @var array<int, string>|null Document paths, walked once per instance */
    private ?array $documents = null;

    /**
     * @param string $root Absolute path of the tree to scan
     * @param array<int, string> $wrapperPatterns Patterns of the router files, relative to the root
     * @param array<int, string> $documentPatterns Patterns of the document files, relative to the root
     * @param array<int, string> $topLevelEntries Entries a root-anchored path may start with
     */
    public function __construct(
        private readonly string $root,
        private readonly array $wrapperPatterns,
        private readonly array $documentPatterns,
        private readonly array $topLevelEntries,
    ) {
    }

    /**
     * @param string $root Absolute path of the repository root
     * @return self Sources covering this repository's own agent docs
     */
    public static function forRepository(string $root): self
    {
        return new self($root, self::WRAPPER_PATTERNS, self::DOCUMENT_PATTERNS, self::TOP_LEVEL_ENTRIES);
    }

    /**
     * @return array<int, string> Paths of the routers, relative to the scanned root, sorted
     */
    public function wrappers(): array
    {
        return $this->wrappers ??= $this->matching($this->wrapperPatterns);
    }

    /**
     * @return array<int, string> Paths of the documents, relative to the scanned root, sorted
     */
    public function documents(): array
    {
        return $this->documents ??= $this->matching($this->documentPatterns);
    }

    /**
     * @param string $directory Directory to list, relative to the scanned root
     * @return array<int, string> Paths of the markdown files directly inside it, relative to the root, sorted
     */
    public function markdownIn(string $directory): array
    {
        return $this->matching([$directory . '/*.md']);
    }

    /**
     * @param string $relativePath Path of a scanned file, relative to the root
     * @return string Contents of the file
     */
    public function read(string $relativePath): string
    {
        return (string)file_get_contents($this->root . '/' . $relativePath);
    }

    /**
     * A directory counts as much as a file: `[demo/chat](../../demo/chat)` and
     * "rules live under `docs/agents/`" are addresses an agent follows, and the
     * extension of the target is not the rule's business either — a `.php` or a
     * `.ts` path goes stale exactly like a `.md` one.
     *
     * @param string $relativePath Path relative to the scanned root
     * @return bool True when the path names something that exists in the tree
     */
    public function exists(string $relativePath): bool
    {
        return file_exists($this->root . '/' . $relativePath);
    }

    /**
     * Reads the whole text rather than a line at a time, because a markdown link
     * reflowed across two lines is still one link, and a rule that scanned lines
     * would see a route the rule checking targets could not.
     *
     * @param string $text Markdown text of a whole file
     * @return array<int, array<int, string>> Target of every markdown link, keyed by the line the target sits on
     */
    public function linksByLine(string $text): array
    {
        $byLine = [];
        foreach ($this->byLine(self::LINK_PATTERN, $text) as $line => $targets) {
            foreach ($targets as $target) {
                $byLine[$line][] = (string)strtok(trim($target), " \t\n");
            }
        }

        return $byLine;
    }

    /**
     * @param string $text Markdown text of a whole file
     * @return array<int, array<int, string>> Text of every backticked span, keyed by the line it sits on
     */
    public function backtickedByLine(string $text): array
    {
        return $this->byLine(self::BACKTICKED_PATTERN, $text);
    }

    /**
     * Blanks the body of every fenced code block, keeping the line count intact so
     * a hit still reports the line it sits on. A sample is not a reference: the
     * document that explains a rule has to show the shapes the rule judges, and
     * reading those as live would report the explanation itself.
     *
     * @param string $text Markdown text of a whole file
     * @return string Same text with every code sample emptied out
     */
    public function prose(string $text): string
    {
        $prose = [];
        $fenced = false;
        foreach (explode("\n", $text) as $line) {
            if (preg_match(self::FENCE_PATTERN, $line) === 1) {
                $fenced = !$fenced;
                $prose[] = '';
                continue;
            }

            $prose[] = $fenced ? '' : $line;
        }

        return implode("\n", $prose);
    }

    /**
     * A bare path addresses something only from the repository root: with a
     * leading slash, or starting with a top-level directory.
     *
     * @param string $target Text written inside a reference
     * @return string|null Path relative to the scanned root, or null when the text addresses nothing
     */
    public function rootAnchoredTarget(string $target): ?string
    {
        $path = $this->addressable($target);
        if ($path === null) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            return $this->normalized($path);
        }

        return in_array(explode('/', $path)[0], $this->topLevelEntries, true) ? $this->normalized($path) : null;
    }

    /**
     * A markdown link is a jump its author wrote on purpose, so it resolves
     * against its own file as well as against the repository root. Both are
     * live conventions in this tree, and honoring one of them alone reported
     * five legitimate links.
     *
     * @param string $target Text written inside the link
     * @param string $relativePath Path of the file carrying the link, relative to the scanned root
     * @return array<int, string> Paths the link may mean, relative to the root; empty when it means none
     */
    public function linkTargets(string $target, string $relativePath): array
    {
        $path = $this->addressable($target);
        if ($path === null) {
            return [];
        }

        $targets = [];
        $rootAnchored = $this->rootAnchoredTarget($target);
        if ($rootAnchored !== null) {
            $targets[] = $rootAnchored;
        }

        $relative = str_starts_with($path, '/')
            ? null
            : $this->normalized(dirname('/' . $relativePath) . '/' . $path);
        if ($relative !== null) {
            $targets[] = $relative;
        }

        return array_values(array_unique($targets));
    }

    /**
     * @param string $target Text written inside a reference
     * @return string|null The path part, or null when the text is not a path at all
     */
    private function addressable(string $target): ?string
    {
        $path = (string)preg_replace(self::CITATION_PATTERN, '', trim(explode('#', $target, 2)[0]));

        if ($path === '' || preg_match(self::EXTERNAL_PATTERN, $path) === 1) {
            return null;
        }

        if (preg_match(self::WHITESPACE_PATTERN, $path) === 1) {
            return null;
        }

        return strpbrk($path, self::PLACEHOLDER_CHARACTERS) === false ? $path : null;
    }

    /**
     * Counts the line in the same text the match came from: `prose()` keeps the
     * line count of the original but not its byte offsets.
     *
     * @param string $pattern Regex whose first group holds the reference text
     * @param string $text Markdown text of a whole file
     * @return array<int, array<int, string>> First group of every match, keyed by the line it sits on
     */
    private function byLine(string $pattern, string $text): array
    {
        $prose = $this->prose($text);
        preg_match_all($pattern, $prose, $found, PREG_OFFSET_CAPTURE);

        $byLine = [];
        foreach ($found[1] as [$reference, $offset]) {
            $byLine[substr_count($prose, "\n", 0, $offset) + 1][] = $reference;
        }

        return $byLine;
    }

    /**
     * A path that climbs above the scanned root leaves the repository, so it is
     * not an address either. Clamping it at the root instead would quietly check
     * a different file that happens to exist and call the reference good.
     *
     * @param string $path Path built from a reference, possibly with `.` and `..` in it
     * @return string|null Same path relative to the scanned root, or null when it does not stay inside it
     */
    private function normalized(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * @param array<int, string> $patterns Patterns relative to the scanned root
     * @return array<int, string> Paths of the matching files, relative to the root, sorted and deduplicated
     */
    private function matching(array $patterns): array
    {
        $paths = [];
        foreach ($patterns as $pattern) {
            foreach ($this->expanded(explode('/', $pattern), '') as $path) {
                $paths[$path] = true;
            }
        }

        $matched = array_keys($paths);
        sort($matched);

        return $matched;
    }

    /**
     * Walks the pattern rather than the tree: a wildcard never descends where the
     * remaining pattern could not match anyway. That is what keeps `demo/*` out of
     * a demo's root-owned `data/`, which the walk has no permission to read.
     *
     * @param array<int, string> $segments Pattern segments still to match
     * @param string $prefix Path matched so far, relative to the scanned root
     * @return array<int, string> Paths of the matching files, relative to the root
     */
    private function expanded(array $segments, string $prefix): array
    {
        if ($segments === []) {
            return is_file($this->root . '/' . $prefix) ? [$prefix] : [];
        }

        $segment = array_shift($segments);
        if ($segment === '**') {
            $matched = $this->expanded($segments, $prefix);
            foreach ($this->entries($prefix) as $entry) {
                if (is_dir($this->root . '/' . $entry)) {
                    $matched = array_merge($matched, $this->expanded(['**', ...$segments], $entry));
                }
            }

            return $matched;
        }

        if (!str_contains($segment, '*')) {
            return $this->expanded($segments, $prefix === '' ? $segment : $prefix . '/' . $segment);
        }

        $matched = [];
        $pattern = '~^' . str_replace('\*', '[^/]*', preg_quote($segment, '~')) . '$~';
        foreach ($this->entries($prefix) as $entry) {
            if (preg_match($pattern, basename($entry)) === 1) {
                $matched = array_merge($matched, $this->expanded($segments, $entry));
            }
        }

        return $matched;
    }

    /**
     * @param string $prefix Directory to list, relative to the scanned root
     * @return array<int, string> Paths of its entries, relative to the root, sorted
     */
    private function entries(string $prefix): array
    {
        $directory = $prefix === '' ? $this->root : $this->root . '/' . $prefix;
        if (!is_dir($directory) || !is_readable($directory)) {
            return [];
        }

        $entries = [];
        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $entries[] = $prefix === '' ? $name : $prefix . '/' . $name;
            }
        }

        return $entries;
    }
}
