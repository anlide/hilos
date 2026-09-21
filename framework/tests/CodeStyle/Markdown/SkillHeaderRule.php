<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Markdown;

/**
 * Enforces rule-authoring.md: a skill wrapper's SKILL.md header parses as
 * strict YAML.
 *
 * Cursor reads the header with no fallback parse. A crooked one prints
 * "Failed to parse skill frontmatter", shows the skill without a description,
 * and the autotrigger never picks it. The check lives in the unit suite, not
 * the installer: an installer failure stops the whole line.
 *
 * The rule judges only the header — the text between the file's first `---`
 * line and the next `---` line. It implements no per-file rule interface: the
 * markdown neighbours do not share one, and this rule has nothing to add.
 */
final class SkillHeaderRule
{
    public const string ID = 'SKILL-HEADER';

    private const string DOC = 'docs/agents/rule-authoring.md';

    /** Unquoted scalars that open with one of these are YAML indicators, not prose. */
    private const string YAML_INDICATORS = '[{*&!%@`';

    /**
     * @param MarkdownSources $sources Files to read; wrappers() is the live skill-header set
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
     * Carries a line number where the complaint sits on a line, and none where
     * the complaint is about a line that is not there — the opening fence, or a
     * missing key. Printing a zero under an absence would be noise.
     *
     * @return iterable<string> One line per header problem
     */
    public function check(): iterable
    {
        foreach ($this->sources->wrappers() as $path) {
            yield from $this->checkFile($path, $this->sources->read($path));
        }
    }

    /**
     * @param string $path Path of the skill file, relative to the scanned root
     * @param string $text Whole file contents
     * @return iterable<string> Hits for this file, in scan order, then missing keys
     */
    private function checkFile(string $path, string $text): iterable
    {
        $lines = $this->linesOf($text);
        if ($lines === [] || !$this->isFence($lines[0])) {
            yield $this->describe($path, 'the file does not open with a --- line');

            return;
        }

        $closeAt = null;
        for ($index = 1, $count = count($lines); $index < $count; $index++) {
            if ($this->isFence($lines[$index])) {
                $closeAt = $index;
                break;
            }
        }

        if ($closeAt === null) {
            yield $this->describe($path, 'the header is not closed by a --- line');

            return;
        }

        $seenName = false;
        $seenDescription = false;
        $inBlock = false;

        for ($index = 1; $index < $closeAt; $index++) {
            $line = $lines[$index];
            $lineNo = $index + 1;

            if ($inBlock) {
                if ($line !== '' && !$this->isIndented($line)) {
                    $inBlock = false;
                } else {
                    continue;
                }
            }

            if (!preg_match('/^([A-Za-z0-9_-]+):(.*)$/', $line, $found)) {
                yield $this->describe(
                    $path,
                    'this line is neither a key: value pair nor the body of a block scalar',
                    $lineNo,
                );
                continue;
            }

            $key = $found[1];
            $value = ltrim($found[2], " \t");
            if ($key === 'name') {
                $seenName = true;
            } elseif ($key === 'description') {
                $seenDescription = true;
            }

            if ($value === '') {
                if ($key === 'name' || $key === 'description') {
                    yield $this->describe($path, $key . ' has an empty value', $lineNo);
                }
                continue;
            }

            if ($this->isBlockScalar($value)) {
                $inBlock = true;
                continue;
            }

            $quote = $value[0];
            if ($quote === '"' || $quote === "'") {
                $trimmed = rtrim($value);
                if (strlen($trimmed) < 2 || !str_ends_with($trimmed, $quote)) {
                    yield $this->describe($path, 'a quoted value is not closed on its line', $lineNo);
                }
                continue;
            }

            yield from $this->unquotedHits($path, $lineNo, $value);
        }

        if (!$seenName) {
            yield $this->describe($path, 'the header carries no name key');
        }
        if (!$seenDescription) {
            yield $this->describe($path, 'the header carries no description key');
        }
    }

    /**
     * @param string $path Path of the skill file, relative to the scanned root
     * @param int $line 1-based line of the unquoted value
     * @param string $value Unquoted scalar text
     * @return iterable<string> One hit per trap the value carries
     */
    private function unquotedHits(string $path, int $line, string $value): iterable
    {
        if (str_contains($value, ': ')) {
            yield $this->describe(
                $path,
                'an unquoted value carries ": ", which ends the scalar and opens a mapping',
                $line,
            );
        }

        if (str_ends_with($value, ':')) {
            yield $this->describe($path, 'an unquoted value ends with a colon', $line);
        }

        $first = $value[0];
        if (str_contains(self::YAML_INDICATORS, $first)) {
            yield $this->describe(
                $path,
                'an unquoted value opens with the YAML indicator ' . $first,
                $line,
            );
        }

        if (str_contains($value, ' #')) {
            yield $this->describe(
                $path,
                'an unquoted value carries " #", which opens a comment',
                $line,
            );
        }
    }

    /**
     * @param string $value Scalar after the colon
     * @return bool True when the value opens a `|` / `>` block, indicators included
     */
    private function isBlockScalar(string $value): bool
    {
        return preg_match('/^[>|][-+]?[0-9]*$/', $value) === 1;
    }

    /**
     * @param string $line One header line, without a trailing newline
     * @return bool True when the line is indented, which a block-scalar body must be
     */
    private function isIndented(string $line): bool
    {
        return $line !== '' && ($line[0] === ' ' || $line[0] === "\t");
    }

    /**
     * @param string $line One file line, without a trailing newline
     * @return bool True when the line is a frontmatter fence
     */
    private function isFence(string $line): bool
    {
        return preg_match('/^---\s*$/', $line) === 1;
    }

    /**
     * @param string $text Whole file contents
     * @return list<string> Lines without their newlines; a trailing newline does not mint an extra empty line
     */
    private function linesOf(string $text): array
    {
        $lines = explode("\n", $text);
        if ($lines !== [] && $lines[array_key_last($lines)] === '') {
            array_pop($lines);
        }

        foreach ($lines as $index => $line) {
            $lines[$index] = rtrim($line, "\r");
        }

        return $lines;
    }

    /**
     * @param string $relativePath Skill file the complaint is about, relative to the scanned root
     * @param string $message Short human-readable essence of the hit
     * @param int|null $line 1-based line when the complaint sits on one
     * @return string Single reportable line
     */
    private function describe(string $relativePath, string $message, ?int $line = null): string
    {
        if ($line === null) {
            return sprintf('%s %s — %s (see %s)', self::ID, $relativePath, $message, self::DOC);
        }

        return sprintf('%s %s:%d — %s (see %s)', self::ID, $relativePath, $line, $message, self::DOC);
    }
}
