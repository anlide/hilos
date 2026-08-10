<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces line-length.md: a PHP line is at most 150 characters wide. The frontend
 * has held its shape by machine since prettier arrived with the npm toolchain; PHP
 * held it by nothing at all, which is how the `DaemonManager` declaration reached
 * 165 columns by the time the limit was chosen and 194 by the time it was broken.
 *
 * Length is counted in UTF-8 characters rather than bytes, because a typographic
 * dash in an English comment weighs three bytes and reads as one column: by bytes
 * the rule would report a line that fits on the screen.
 *
 * The rule is handed tokens like every other one, and glues the source text back
 * together from them — `token_get_all()` is lossless, so the text it rebuilds is
 * the file byte for byte. It reads that text as lines rather than judging tokens,
 * because what is too wide is the line, and no token owns one.
 *
 * A line inside a heredoc or a nowdoc body is not checked. Breaking one there puts
 * a `\n` into the string itself — into an LLM prompt, into the body of a SQL
 * statement — and a rule about the shape of code may not ask for the content to
 * change. The exception is syntax, not a marker: it cannot be written above an
 * arbitrary line to buy silence for it.
 */
final class LineLengthRule implements CodeStyleRule
{
    public const string ID = 'LINE-LENGTH';

    private const string DOC = 'docs/agents/code-style/line-length.md';

    /** Widest line the rule allows; chosen below the declaration this rule was written for. */
    private const int LIMIT = 150;

    /** Counted in characters of this encoding, never in bytes. */
    private const string ENCODING = 'UTF-8';

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
     * @return iterable<Violation> One entry per line wider than the limit
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $heredocBody = $this->heredocBodyLines($tokens);

        foreach (explode("\n", $this->sourceText($tokens)) as $index => $text) {
            $line = $index + 1;
            if (isset($heredocBody[$line])) {
                continue;
            }

            $width = mb_strlen($text, self::ENCODING);
            if ($width <= self::LIMIT) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $line,
                sprintf('line is %d characters, limit %d', $width, self::LIMIT),
            );
        }
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return string The file as it was read, rebuilt from its tokens
     */
    private function sourceText(array $tokens): string
    {
        $source = '';

        foreach ($tokens as $token) {
            $source .= is_array($token) ? $token[1] : $token;
        }

        return $source;
    }

    /**
     * Walks the tokens once to find the bodies of the heredocs and nowdocs. The
     * opening token carries `<<<ID` together with the newline that ends it, so the
     * body starts on the next line; the closing token sits on the line of the
     * marker. Both of those lines are ordinary code and stay under the rule — only
     * what lies between them is the string.
     *
     * Nesting is counted rather than assumed away: a body is remembered by the
     * outermost pair, so an inner heredoc cannot close the outer one's range early.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, bool> Lines that lie inside a heredoc/nowdoc body, keyed by line number
     */
    private function heredocBodyLines(array $tokens): array
    {
        $body = [];
        $line = 1;
        $depth = 0;
        $opened = 0;

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_START_HEREDOC) {
                if ($depth === 0) {
                    $opened = $line;
                }
                $depth++;
            } elseif (is_array($token) && $token[0] === T_END_HEREDOC) {
                $depth--;
                if ($depth === 0) {
                    for ($inside = $opened + 1; $inside < $line; $inside++) {
                        $body[$inside] = true;
                    }
                }
            }

            $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
        }

        return $body;
    }
}
