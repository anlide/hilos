<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces error-suppression.md: `@` never silences a warning by itself. A
 * suppressed call is legal only under a marker comment on the line directly
 * above it, and the marker has to name what is checked instead of the warning.
 *
 * Only real tokens are read, so `@throws` inside a docblock, `"@unlink(x)"`
 * inside a string literal and the `#[Attr]` attribute syntax produce no `@`
 * token at all and cannot be hits.
 */
final class ErrorSuppressionRule implements CodeStyleRule
{
    public const string ID = 'ERROR-SUPPRESSION';

    private const string DOC = 'docs/agents/code-style/error-suppression.md';

    /** The marker that legalizes one suppressed call; the reason after the colon is the point of it. */
    private const string MARKER_PATTERN = '~^//\s*warning-suppressed:(?<reason>.*)$~';

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
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per suppressed call that no marker covers
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $lines = $this->lineNumbers($tokens);

        foreach ($tokens as $index => $token) {
            if ($token !== '@') {
                continue;
            }

            $line = $lines[$index];
            $reason = $this->markerReason($tokens, $lines, $index);
            if ($reason === null) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $line,
                    '@ silences a warning with no `// warning-suppressed:` marker on the line above',
                );
                continue;
            }

            if (trim($reason) === '') {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $line,
                    'the `// warning-suppressed:` marker above the call names no reason',
                );
            }
        }
    }

    /**
     * Single-character tokens carry no line of their own, so the walk keeps the
     * line the last multi-character token ended on.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, int> Start line of every token, single-character ones included
     */
    private function lineNumbers(array $tokens): array
    {
        $lines = [];
        $line = 1;

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                $lines[$index] = $line;
                continue;
            }

            $lines[$index] = $token[2];
            $line = $token[2] + substr_count($token[1], "\n");
        }

        return $lines;
    }

    /**
     * Reads the marker that covers a suppressed call. The walk gives up as soon
     * as it passes above the previous line: "directly above" is the whole point
     * of the marker, and a comment further up belongs to something else.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, int> $lines Start line of every token
     * @param int $index Index of the `@` token
     * @return string|null Text after the colon, or null when no marker covers the call
     */
    private function markerReason(array $tokens, array $lines, int $index): ?string
    {
        $markerLine = $lines[$index] - 1;

        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if ($lines[$cursor] < $markerLine) {
                return null;
            }

            $token = $tokens[$cursor];
            if (!is_array($token) || $token[0] !== T_COMMENT || $lines[$cursor] !== $markerLine) {
                continue;
            }

            return preg_match(self::MARKER_PATTERN, rtrim($token[1]), $found) === 1 ? $found['reason'] : null;
        }

        return null;
    }
}
