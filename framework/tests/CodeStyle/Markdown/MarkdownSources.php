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

    private const string QUOTE = '~^ {0,3}> ?~';
    private const string LIST_ITEM = '~^ {0,3}(?:[-+*]|([0-9]{1,9})[.)])(?: {1,4}(?! )| |$)~';
    private const string ATX = '~^ {0,3}#{1,6}(?:[ \t]+|$)~';
    private const string DIVIDER = '~^ {0,3}(?:=+|-+|(?:\*[ \t]*){3,}|(?:_[ \t]*){3,}|(?:-[ \t]*){3,})[ \t]*$~';
    /** HTML block openers that can interrupt a paragraph (CommonMark §4.6, types 1 through 6). */
    private const string HTML_BLOCK_OPEN = '~^ {0,3}<(?:!--|\?|![a-zA-Z]|!\[CDATA\[|(?i:'
        . '(?:pre|script|style|textarea)(?=[ \t>]|$)|/?(?:address|article|aside|base|basefont|blockquote|body|caption|center|col|colgroup'
        . '|dd|details|dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset|h[1-6]|head|header|hr|html|iframe'
        . '|legend|li|link|main|menu|menuitem|nav|noframes|ol|optgroup|option|p|param|search|section|summary|table|tbody|td|tfoot|th'
        . '|thead|title|tr|track|ul)(?=[ \t]|/?>|$)))~';
    private const string TABLE_DELIMITER = '~^\h*\|?\h*:?-+:?\h*(?:\|\h*:?-+:?\h*)*\|?\h*$~';
    private const string CELL_DIVIDER = '~\x5c.(*SKIP)(*F)|\|~';

    /** Consume escapes and whole backtick runs in order. Inside a matched span even a backslash is literal. */
    private const string INLINE_LITERAL = '(?<escape>\x5c[[:punct:]])|(?<span>(?<ticks>`++)(?<code>.+?)(?<!`)\k<ticks>(?!`))|`++';

    /**
     * An ordinary bracket pair belongs to a link label only if it has no destination
     * of its own. Images can occur inside links and keep their balanced alt text.
     */
    private const string INLINE_PATTERN = '~' . self::INLINE_LITERAL . '|' . <<<'REGEX'
        (?<image>!)? \[ (?<label> (?(image) (?&alt) | (?:
              (?&text) | !\[(?&alt)\](?&destination) | !
            | \[(?&label)\] (?! (?&destination) )
        )*+ ) ) \]
        (?<destination> \(
            \s* (?<reference> <[^<>\n]*> | (?<path> (?: [^()\s]++ | \((?&path)\) )*+ ) )
            (?: \s+ (?:
                  "(?: [^"\\]++ | \\. )*"
                | '(?: [^'\\]++ | \\. )*'
                | \( (?: [^()\\]++ | \\. )* \)
            ) )?
            \s*
        \) )
        (?(DEFINE)
            (?<text> [^][`\\!]++ | (?&escape) | \\ | (?&span) | `++ )
            (?<alt> (?: (?&text) | ! | \[(?&alt)\] )*+ )
        )
        ~sx
        REGEX;

    /** Punctuation excluded from a heading anchor, including the delimiters of inline markup. */
    private const string HEADING_PUNCTUATION = '~[^\p{L}\p{M}\p{N}\p{Pc}\- ]~u';

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

    private const string FENCE_OPEN = '#^ {0,3}(`{3,}(?=[^`]*$)|~{3,})#';
    private const string FENCE_CLOSE = '#^ {0,3}(?:`{3,}|~{3,})[ \t]*$#';

    /** @var array<int, string>|null Wrapper paths, walked once per instance */
    private ?array $wrappers = null;

    /** @var array<int, string>|null Document paths, walked once per instance */
    private ?array $documents = null;

    /** @var array<string, array<int, string>> Anchors of each referenced Markdown file */
    private array $anchors = [];

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
     * A subdirectory of a judged directory is still that directory: a file put one
     * level down is the same file, and a rule that stopped at the top would be
     * walked around by making a folder. The pattern expands `**` as zero or more
     * directories, so the flat files come along with the nested ones.
     *
     * @param string $directory Directory to walk, relative to the scanned root
     * @return array<int, string> Paths of the markdown files at any depth inside it, relative to the root, sorted
     */
    public function markdownUnder(string $directory): array
    {
        return $this->matching([$directory . '/**/*.md']);
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
     * @param string $relativePath Path relative to the scanned root
     * @return bool True when the target is a Markdown file whose anchors can be checked
     */
    public function isMarkdownFile(string $relativePath): bool
    {
        return str_ends_with($relativePath, '.md') && is_file($this->root . '/' . $relativePath);
    }

    /**
     * @param string $relativePath Markdown file relative to the scanned root
     * @return array<int, string> Heading and explicit HTML anchors in that file
     */
    public function anchors(string $relativePath): array
    {
        return $this->anchors[$relativePath] ??= $this->extractAnchors($this->read($relativePath));
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
        return $this->byLine('reference', $text);
    }

    /**
     * @param string $text Markdown text of a whole file
     * @return array<int, array<int, string>> Text of every backticked span, keyed by the line it sits on
     */
    public function backtickedByLine(string $text): array
    {
        return $this->byLine('code', $text);
    }

    /**
     * Blanks fenced and indented code, keeping the line count intact so
     * a hit still reports the line it sits on. A sample is not a reference: the
     * document that explains a rule has to show the shapes the rule judges, and
     * reading those as live would report the explanation itself.
     *
     * @param string $text Markdown text of a whole file
     * @return string Same text with every code sample emptied out
     */
    public function prose(string $text): string
    {
        $prose = explode("\n", $text);
        foreach ($this->blocks($text) as $number => [$kind]) {
            if ($kind === 'code') {
                $prose[$number - 1] = '';
            }
        }

        return implode("\n", $prose);
    }

    /**
     * @param string $target Text written inside a reference
     * @return string|null Text after the first hash, or null for an absent or empty fragment
     */
    public function fragment(string $target): ?string
    {
        $hash = strpos($target, '#');
        if ($hash === false) {
            return null;
        }

        $fragment = trim(substr($target, $hash + 1));

        return $fragment === '' ? null : $fragment;
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
        if (str_starts_with($target, '#')) {
            return [$relativePath];
        }

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
     * @param string $text Contents of one Markdown file
     * @return array<int, string> Anchors generated from prose headings and declared by HTML anchor elements
     */
    private function extractAnchors(string $text): array
    {
        $anchors = [];
        $occurrences = [];
        foreach ($this->blocks($text) as [$kind, $lines]) {
            if ($kind !== 'heading') {
                continue;
            }

            $slug = $this->headingSlug($lines[0]);
            $repeat = $occurrences[$slug] ?? 0;
            $anchors[] = $repeat === 0 ? $slug : $slug . '-' . $repeat;
            $occurrences[$slug] = $repeat + 1;
        }

        preg_match_all('~<a(?=\s)((?:[^>"\']++|"[^"]*"|\'[^\']*\')*)>~i', $text, $tags);
        foreach ($tags[1] as $attributes) {
            preg_match_all('~\s+([^\s=/>]+)\s*=\s*(["\'])(.*?)\2~s', $attributes, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $name, , $value]) {
                if (in_array(strtolower($name), ['id', 'name'], true)) {
                    $anchors[] = $value;
                }
            }
        }

        return $anchors;
    }

    /**
     * @param string $heading ATX heading text after its opening hashes
     * @return string Heading anchor before disambiguating repeated headings
     */
    private function headingSlug(string $heading): string
    {
        $heading = (string)preg_replace('~(?:^|\h+)#+$~', '', trim($heading));
        $heading = (string)preg_replace_callback(
            self::INLINE_PATTERN,
            static fn (array $match): string => $match['label'] ?? $match[0],
            $heading,
        );
        $heading = (string)preg_replace_callback(
            '~' . self::INLINE_LITERAL . '~s',
            static fn (array $literal): string => isset($literal['code'])
                ? (string)preg_replace(self::HEADING_PUNCTUATION, '', $literal['code'])
                : $literal[0],
            $heading,
        );
        $heading = mb_strtolower(strip_tags($heading), 'UTF-8');
        $heading = (string)preg_replace(self::HEADING_PUNCTUATION, '', $heading);

        return str_replace(' ', '-', $heading);
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
     * A list entry stores its continuation indent. A quote stores its explicit
     * marker. A literal block lasts only while every enclosing container continues.
     *
     * @param string $line Source line
     * @param array<int, int|string> $containers Enclosing list indents and quote markers
     * @param bool $literal Whether a fence or HTML comment is open
     * @param bool $paragraph Whether the previous line belongs to an open paragraph
     * @return array{string, 'matched'|'changed'|'lazy'} Local line and how it continues its containers
     */
    private function containerLine(string $line, array &$containers, bool $literal, bool $paragraph): array
    {
        // Expand tabs only in the prefix that can contain container markers or indent.
        if (str_contains($line, "\t")) {
            $width = strspn($line, " \t>+-*.0123456789)");
            $prefix = (string)preg_replace_callback(
                '~([^\t]*)\t~',
                static fn (array $match): string => $match[1] . str_repeat(' ', 4 - strlen($match[1]) % 4),
                substr($line, 0, $width),
            );
            $line = $prefix . substr($line, $width);
        }

        $changed = false;
        foreach ($containers as $depth => $owner) {
            if ($owner === '>') {
                if (preg_match(self::QUOTE, $line, $prefix) === 1) {
                    $line = substr($line, strlen($prefix[0]));
                    continue;
                }
            } elseif (strspn($line, ' ') >= $owner || trim($line) === '') {
                $line = substr($line, $owner);
                continue;
            }

            if ($paragraph && $this->continuesWithoutPrefix($line, $owner !== '>')) {
                return [$line, 'lazy'];
            }

            $containers = array_slice($containers, 0, $depth);
            $literal = false;
            $changed = true;
            break;
        }

        while (!$literal) {
            if (preg_match(self::QUOTE, $line, $prefix) === 1) {
                $containers[] = '>';
            } elseif (
                preg_match(self::LIST_ITEM, $line, $prefix) === 1
                && preg_match(self::DIVIDER, $line) !== 1
                && ($changed || !$paragraph || $this->itemInterruptsParagraph($line, $prefix))
            ) {
                // An empty item reserves one column after its marker, regardless of trailing spaces.
                $containers[] = trim(substr($line, strlen($prefix[0]))) === ''
                    ? strlen(rtrim($prefix[0], ' ')) + 1
                    : strlen($prefix[0]);
            } else {
                break;
            }

            $line = substr($line, strlen($prefix[0]));
            $changed = true;
        }

        return [$line, $changed ? 'changed' : 'matched'];
    }

    /**
     * @param string $line Text at the potential start of a new list
     * @param array<int, string> $marker Matched list prefix and optional ordinal
     * @return bool Whether the first item can interrupt an open paragraph
     */
    private function itemInterruptsParagraph(string $line, array $marker): bool
    {
        return (!isset($marker[1]) || (int)$marker[1] === 1)
            && trim(substr($line, strlen($marker[0]))) !== '';
    }

    /**
     * @param string $line Text after any explicit container prefixes
     * @return bool Whether a blank line, divider or HTML opener ends the paragraph
     */
    private function paragraphBreak(string $line): bool
    {
        return trim($line) === '' || preg_match(self::DIVIDER, $line) === 1 || preg_match(self::HTML_BLOCK_OPEN, $line) === 1;
    }

    /**
     * @param string $line Text after any explicit outer container prefixes
     * @param bool $inList Whether a list marker here would start a sibling item
     * @return bool Whether the open paragraph continues without the remaining prefixes
     */
    private function continuesWithoutPrefix(string $line, bool $inList): bool
    {
        if ($this->paragraphBreak($line)) {
            return false;
        }
        if (preg_match(self::LIST_ITEM, $line, $item) === 1) {
            return !$inList && !$this->itemInterruptsParagraph($line, $item);
        }

        return preg_match(self::QUOTE, $line) !== 1
            && preg_match(self::ATX, $line) !== 1
            && preg_match(self::FENCE_OPEN, $line) !== 1;
    }

    /**
     * @param string $text Markdown source of a whole file
     * @return iterable<int, array{string, array<int, string>}> Block kind and local lines or table cells, keyed by first source line
     */
    private function blocks(string $text): iterable
    {
        $containers = [];
        $terminator = null;
        $tableWidth = 0;
        $paragraph = [];
        foreach (explode("\n", $text) as $index => $raw) {
            $number = $index + 1;
            [$local, $continuation] = $this->containerLine(rtrim($raw, "\r"), $containers, $terminator !== null, $paragraph !== []);
            if ($continuation === 'changed') {
                $terminator = null;
                $tableWidth = 0;
                if ($paragraph !== []) {
                    yield $number - count($paragraph) => ['paragraph', $paragraph];
                    $paragraph = [];
                }
            }

            if ($terminator === '-->') {
                $kind = 'break';
                if (str_contains($local, '-->')) {
                    $terminator = null;
                }
            } elseif ($terminator !== null) {
                $kind = 'code';
                if (str_starts_with(ltrim($local, ' '), $terminator) && preg_match(self::FENCE_CLOSE, $local) === 1) {
                    $terminator = null;
                }
            } elseif (preg_match(self::FENCE_OPEN, $local, $match) === 1) {
                $kind = 'code';
                $terminator = $match[1];
            } elseif ($this->paragraphBreak($local)) {
                $kind = 'break';
                if (str_starts_with(ltrim($local, ' '), '<!--') && !str_contains($local, '-->')) {
                    $terminator = '-->';
                }
            } elseif ($paragraph === [] && str_starts_with($local, '    ')) {
                $kind = 'code';
            } elseif (preg_match(self::ATX, $local, $match) === 1) {
                $kind = 'heading';
                $local = substr($local, strlen($match[0]));
            } elseif ($tableWidth > 0) {
                yield $number => ['row', array_slice($this->cells($local), 0, $tableWidth)];
                continue;
            } elseif ($continuation === 'matched' && $paragraph !== [] && ($header = $this->tableHeader(end($paragraph), $local)) !== []) {
                $headerNumber = $number - 1;
                array_pop($paragraph);
                if ($paragraph !== []) {
                    yield $headerNumber - count($paragraph) => ['paragraph', $paragraph];
                    $paragraph = [];
                }
                $tableWidth = count($header);
                yield $headerNumber => ['row', $header];
                continue;
            } else {
                $paragraph[] = $local;
                continue;
            }

            $tableWidth = 0;
            if ($paragraph !== []) {
                yield $number - count($paragraph) => ['paragraph', $paragraph];
                $paragraph = [];
            }
            yield $number => [$kind, [$local]];
        }

        if ($paragraph !== []) {
            yield $number - count($paragraph) + 1 => ['paragraph', $paragraph];
        }
    }

    /**
     * @param string $line A possible table row
     * @return array<int, string> Cells between unescaped pipes, excluding optional outer borders
     */
    private function cells(string $line): array
    {
        $cells = preg_split(self::CELL_DIVIDER, trim($line));
        if ($cells[0] === '') {
            array_shift($cells);
        }
        if (end($cells) === '') {
            array_pop($cells);
        }

        return $cells;
    }

    /**
     * @param string $header Previous paragraph line
     * @param string $delimiter Current local line
     * @return array<int, string> Header cells when the delimiter confirms the same width
     */
    private function tableHeader(string $header, string $delimiter): array
    {
        if (!str_contains($header, '|') || preg_match(self::TABLE_DELIMITER, $delimiter) !== 1) {
            return [];
        }

        $cells = $this->cells($header);

        return count($cells) === count($this->cells($delimiter)) ? $cells : [];
    }

    /**
     * A link can carry a routed span in its label. Consuming its complete title
     * keeps quoted examples there out of both sets of references.
     *
     * @param string $block Text of one inline block
     * @param string $group Requested reference or code capture
     * @return iterable<int, string> References keyed by byte offset within the block
     */
    private function inlineReferences(string $block, string $group): iterable
    {
        preg_match_all(self::INLINE_PATTERN, $block, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            if (isset($match[$group]) && $match[$group][1] >= 0) {
                [$value, $offset] = $match[$group];
                yield $offset => $value;
            }
            if ($group === 'code' && isset($match['label'])) {
                [$label, $offset] = $match['label'];
                preg_match_all('~' . self::INLINE_LITERAL . '~s', $label, $literals, PREG_OFFSET_CAPTURE);
                foreach ($literals['code'] as [$code, $at]) {
                    if ($at >= 0) {
                        yield $offset + $at => $code;
                    }
                }
            }
        }
    }

    /**
     * @param string $group Named inline match containing the requested reference
     * @param string $text Markdown text of a whole file
     * @return array<int, array<int, string>> References keyed by the line each sits on
     */
    private function byLine(string $group, string $text): array
    {
        $byLine = [];
        foreach ($this->blocks($text) as $first => [$kind, $lines]) {
            if ($kind === 'code' || $kind === 'break') {
                continue;
            }
            foreach ($kind === 'row' ? $lines : [implode("\n", $lines)] as $block) {
                foreach ($this->inlineReferences($block, $group) as $offset => $value) {
                    $byLine[$first + substr_count($block, "\n", 0, $offset)][] = $value;
                }
            }
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
