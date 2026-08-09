<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces method-contracts.md: the empty string is not a value meaning "no value".
 * `?? ''` mints one at the very place a missing value should have stayed missing,
 * and every reader below has to know that the empty string is not data.
 *
 * Only real tokens are read, so `?? ''` inside a comment or quoted inside a string
 * literal is not a hit. The rule is narrower than the document it enforces: it
 * judges `??` alone and leaves the `: ''` branch of a ternary to review, because
 * that branch is also how a legitimate optional fragment is rendered into a
 * concatenation.
 *
 * A place where the empty string arrives from outside the process is legal, and a
 * `// external-boundary: <reason>` marker on the line directly above says so. The
 * form is the one `ErrorSuppressionRule` already uses, so the repository carries
 * one way of legalizing a single occurrence rather than two.
 */
final class EmptyStringSentinelRule implements CodeStyleRule
{
    public const string ID = 'EMPTY-STRING-SENTINEL';

    private const string DOC = 'docs/agents/code-style/method-contracts.md';

    /** Both spellings of the literal; `token_get_all()` keeps the quotes. */
    private const array EMPTY_STRING_LITERALS = ["''", '""'];

    /** The marker that legalizes one occurrence; the reason after the colon is the point of it. */
    private const string MARKER_PATTERN = '~^//\s*external-boundary:(?<reason>.*)$~';

    /**
     * Path fragments the rule judges, relative to the scanned root. The zone grows
     * one phase at a time: turned on everywhere at once, the baseline would become
     * a list of exceptions rather than a list of owed work.
     */
    private const array ZONE = [
        'Core/Router',
        'Core/Page',
        'Core/Sync',
        'Core/Agent/DTO',
        'Core/Daemon',
        'Core/Table/DTO',
        'Core/Source',
        'Socket/WebSocket/DTO',
        'Socket/Worker/DTO',
        'Socket/Command/DTO',
        'Cluster/Peer/DTO',
        'API',
        'Auth',
        'Backup',
        'Database',
        'LLM',
        'Log',
        'Mail',
        'Notification',
        'Pages',
        'ProtectedMode',
        'Push',
        'Runtime',
        'Sms',
        'Tables',
        'Utils',
        'Hilos.php',
    ];

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
     * The path is read relative to the scanned root, so the fixtures are judged by
     * the very same code as the real sources.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per minted empty string
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (!$this->isInZone($relativePath)) {
            return;
        }

        $lines = $this->lineNumbers($tokens);

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_COALESCE) {
                continue;
            }

            $fallbackIndex = $this->significantIndex($tokens, $index);
            $fallback = $fallbackIndex === null ? null : $tokens[$fallbackIndex];
            if (!is_array($fallback) || $fallback[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            if (!in_array($fallback[1], self::EMPTY_STRING_LITERALS, true)) {
                continue;
            }

            $reason = $this->markerReason($tokens, $lines, (int)$fallbackIndex);
            if ($reason === null) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $fallback[2],
                    '?? \'\' turns a missing value into an empty string; keep it null or make the field required',
                );
                continue;
            }

            if (trim($reason) === '') {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $fallback[2],
                    'the `// external-boundary:` marker above the fallback names no reason',
                );
            }
        }
    }

    /**
     * A zone entry matches a whole path segment, wherever it sits: the fixtures
     * repeat the segments of the real zone under `Bad/` and `Good/`, and would fall
     * outside a prefix match.
     *
     * @param string $relativePath File path relative to the scanned root
     * @return bool True when this file is inside the checked zone
     */
    private function isInZone(string $relativePath): bool
    {
        $path = '/' . $relativePath;

        foreach (self::ZONE as $zone) {
            $anchored = '/' . $zone;
            if (str_ends_with($path, $anchored) || str_contains($path, $anchored . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk forward from
     * @return int|null Index of the nearest token that is not whitespace or a comment
     */
    private function significantIndex(array $tokens, int $index): ?int
    {
        for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $cursor;
            }
        }

        return null;
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
     * Reads the marker that legalizes one fallback. The walk gives up as soon as it
     * passes above the previous line: the marker has to sit directly above the very
     * occurrence it explains, or it stops being a classification of that place.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, int> $lines Start line of every token
     * @param int $index Index of the empty string literal
     * @return string|null Text after the colon, or null when no marker covers the fallback
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
