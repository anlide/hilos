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
 */
final class EmptyStringSentinelRule implements CodeStyleRule
{
    public const string ID = 'EMPTY-STRING-SENTINEL';

    private const string DOC = 'docs/agents/code-style/method-contracts.md';

    /** Both spellings of the literal; `token_get_all()` keeps the quotes. */
    private const array EMPTY_STRING_LITERALS = ["''", '""'];

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
        'Socket/WebSocket/DTO',
        'Socket/Worker/DTO',
        'Socket/Command/DTO',
        'Cluster/Peer/DTO',
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

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_COALESCE) {
                continue;
            }

            $fallback = $this->significantToken($tokens, $index, 1);
            if (!is_array($fallback) || $fallback[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            if (in_array($fallback[1], self::EMPTY_STRING_LITERALS, true)) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $fallback[2],
                    '?? \'\' turns a missing value into an empty string; keep it null or make the field required',
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
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return string|array{0: int, 1: string, 2: int}|null Nearest token that is not whitespace or a comment
     */
    private function significantToken(array $tokens, int $index, int $step): string|array|null
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $tokens[$cursor];
            }
        }

        return null;
    }
}
