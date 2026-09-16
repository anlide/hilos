<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces the dialect of spelling.md: American English in every piece of English a
 * file carries — a name, a string, a comment, a doc block.
 *
 * The word list is the table of that document and nothing else: six pairs, read off
 * the table in the order it writes them, with no seventh pair and no rule of endings
 * behind them. A word is matched together with its own forms — `behavioural` and
 * `colours` are hits because the table names a word rather than its singular — and
 * `neighbor/neighbour` is not in the table, so the rule is silent on that word by
 * construction; the Exceptions section of the document says why.
 *
 * Where a match opens is an identifier boundary, not a whitespace one: a non-letter
 * to the left, or the hump of a camelCase name — a lowercase letter, a digit or an
 * underscore followed by a capital. A plain `\b` would lose `PASSKEY_CANCELLED_*`,
 * since it never fires between an underscore and a letter. The match closes after
 * the trailing lowercase letters, which is how a word form is taken in, and it does
 * not open inside a word: `discolouration` is silent.
 *
 * Tokens are not a narrowing here but the position: the subject is the English text
 * of the file entire, so every text-bearing category is read — comments and doc
 * blocks, string literals and heredoc bodies, names and variables, and the text
 * outside PHP tags. The TypeScript half of the same rule id lives in
 * `framework/frontend/codestyle/spelling.ts` and prints the same line.
 *
 * Two files write the British forms on purpose and are exempt by the tail of their
 * path: this rule, whose table is the dictionary, and the fixture test that pins the
 * report lines naming both spellings.
 */
final class SpellingRule implements CodeStyleRule
{
    public const string ID = 'SPELLING';

    private const string DOC = 'docs/agents/code-style/spelling.md';

    /**
     * The six pairs of spelling.md, British keyed to American, in the order the table
     * writes them. The table is the source and this constant its reading: a seventh
     * pair is added to the document first.
     *
     * @var array<string, string>
     */
    private const array AMERICAN_BY_BRITISH = [
        'licence' => 'license',
        'colour' => 'color',
        'behaviour' => 'behavior',
        'serialise' => 'serialize',
        'organise' => 'organize',
        'cancelled' => 'canceled',
    ];

    /**
     * The token categories that carry English text: comments and doc blocks, string
     * literals and the bodies of interpolated strings and heredocs, names and
     * variables, and the text outside PHP tags. Keywords and punctuation are the
     * language's own spelling and carry none of ours.
     *
     * @var array<int, int>
     */
    private const array TEXT_TOKENS = [
        T_DOC_COMMENT,
        T_COMMENT,
        T_CONSTANT_ENCAPSED_STRING,
        T_ENCAPSED_AND_WHITESPACE,
        T_STRING,
        T_VARIABLE,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
        T_INLINE_HTML,
    ];

    /**
     * The files that write the British forms on purpose, each with its reason: this
     * rule holds the table, and the fixture test pins report lines that name both
     * spellings. Matched by the tail of the path, because a rule is handed the path
     * relative to its root and never learns which root that is.
     *
     * @var array<int, string>
     */
    private const array DICTIONARY_FILES = [
        'CodeStyle/Rule/SpellingRule.php',
        'Unit/CodeStyle/RuleFixtureTest.php',
    ];

    /**
     * Where a match opens: a non-letter on the left, or a camelCase hump — a lowercase
     * letter, a digit or an underscore directly before a capital. Case-sensitive on
     * purpose, unlike the word it precedes: the hump IS the case.
     */
    private const string OPEN = '(?:(?<![A-Za-z])|(?<=[a-z0-9_])(?=[A-Z]))';

    /** Where a match closes: after the trailing lowercase letters, which take the word form in. */
    private const string CLOSE = '[a-z]*';

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
     * @return iterable<Violation> One entry per British form, in file order
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if ($this->isDictionaryFile($relativePath)) {
            return;
        }

        $pattern = $this->pattern();
        foreach ($tokens as $token) {
            if (!is_array($token) || !in_array($token[0], self::TEXT_TOKENS, true)) {
                continue;
            }

            preg_match_all($pattern, $token[1], $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($matches as $match) {
                [$british, $offset] = $match[0];
                $stem = $match[1][0];

                yield new Violation(
                    self::ID,
                    $relativePath,
                    $token[2] + substr_count(substr($token[1], 0, $offset), "\n"),
                    sprintf('write "%s", not "%s"', $this->american($stem, substr($british, strlen($stem))), $british),
                );
            }
        }
    }

    /**
     * The matcher, assembled from the table so that the table stays the only list:
     * `open`, then one of the six words in any case, then `close`.
     *
     * @return string PCRE pattern with the British stem as its first group
     */
    private function pattern(): string
    {
        return '/' . self::OPEN . '(?i:(' . implode('|', array_keys(self::AMERICAN_BY_BRITISH)) . '))' . self::CLOSE . '/';
    }

    /**
     * The American word in the case the British one was written, carrying the same
     * tail: `Behavioural` reads back as `Behavioral`, `CANCELLED` as `CANCELED`,
     * `serialises` as `serializes`. The report says what to type, not what to look up.
     *
     * @param string $stem The British word as matched, in its own case
     * @param string $tail The lowercase letters the match took in after the word
     * @return string The American form to write instead
     */
    private function american(string $stem, string $tail): string
    {
        $american = self::AMERICAN_BY_BRITISH[strtolower($stem)];

        if (strtoupper($stem) === $stem) {
            return strtoupper($american) . $tail;
        }
        if (strtoupper($stem[0]) === $stem[0]) {
            return ucfirst($american) . $tail;
        }

        return $american . $tail;
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @return bool True when the file is one of the two that hold the British forms on purpose
     */
    private function isDictionaryFile(string $relativePath): bool
    {
        foreach (self::DICTIONARY_FILES as $file) {
            if (str_ends_with($relativePath, $file)) {
                return true;
            }
        }

        return false;
    }
}
