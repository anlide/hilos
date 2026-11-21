<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\SqlTextRule;
use Hilos\Tests\CodeStyle\Violation;

/** Reports Cyrillic in PHP comments, PHP parking labels and full-line SQL comments. */
final class CommentLanguageRule implements CodeStyleRule, SqlTextRule
{
    public const string ID = 'COMMENT-LANGUAGE';

    private const string DOC = 'docs/agents/code-style/comment-language.md';
    private const string CUTOFF = '2026-11-21';
    private const string LABEL = 'FIXME\(F-\d+, (\d{4}-\d{2}-\d{2}|\d{2}\.\d{2}\.\d{4})\)';
    private const string CYRILLIC = '/\p{Cyrillic}/u';
    private const string COMMENT_MESSAGE = 'write this comment in English';
    private const string LABEL_MESSAGE = 'write this parking label in English';

    /** @return string Rule id shared by PHP and SQL findings */
    public function id(): string
    {
        return self::ID;
    }

    /** @return string Document defining which comments and labels must be in English */
    public function doc(): string
    {
        return self::DOC;
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> Comment and parking-label findings in source order
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $lastLabelToken = -1;
        $checkLabel = false;
        $lastReportedLine = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            [$type, $text, $startLine] = $token;
            if ($type === T_COMMENT || $type === T_DOC_COMMENT) {
                $message = self::COMMENT_MESSAGE;
            } elseif ($type === T_CONSTANT_ENCAPSED_STRING) {
                if ($index > $lastLabelToken) {
                    if (!preg_match('/^[bB]?[\'"]FIXME\(F-/', $text)) {
                        continue;
                    }
                    [$lastLabelToken, $label] = $this->parkingLabel($tokens, $index);
                    $checkLabel = !$this->hasOldLabel($label, atStart: true);
                }
                if (!$checkLabel) {
                    continue;
                }
                $message = self::LABEL_MESSAGE;
            } else {
                continue;
            }

            foreach (preg_split('/\r\n|\r|\n/', $text) as $offset => $line) {
                $lineNumber = $startLine + $offset;
                if (($lastReportedLine[$message] ?? null) === $lineNumber || !preg_match(self::CYRILLIC, $line)) {
                    continue;
                }
                if ($message === self::COMMENT_MESSAGE && $this->hasOldLabel($line)) {
                    continue;
                }
                $lastReportedLine[$message] = $lineNumber;
                yield new Violation(self::ID, $relativePath, $lineNumber, $message);
            }
        }
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param string $text Contents of the migration
     * @return iterable<Violation> Findings on lines the migration reader skips as comments
     */
    public function checkSql(string $relativePath, string $text): iterable
    {
        foreach (explode("\n", $text) as $offset => $line) {
            $line = trim($line);
            if ((str_starts_with($line, '--') || str_starts_with($line, '#'))
                && preg_match(self::CYRILLIC, $line) && !$this->hasOldLabel($line)
            ) {
                yield new Violation(self::ID, $relativePath, $offset + 1, self::COMMENT_MESSAGE);
            }
        }
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $start Index of the literal starting the label
     * @return array{int, string} Last joined literal's index and the text of the whole label
     */
    private function parkingLabel(array $tokens, int $start): array
    {
        $label = '';
        $end = $start;
        $expectDot = false;
        for ($index = $start; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($expectDot && $token === '.') {
                $expectDot = false;
                continue;
            }
            if ($expectDot || !is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                break;
            }
            $literal = $token[1];
            $label .= substr($literal, str_contains('bB', $literal[0]) ? 2 : 1, -1);
            $end = $index;
            $expectDot = true;
        }

        return [$end, $label];
    }

    /**
     * @param string $text Physical comment line or text of a PHP parking label
     * @param bool $atStart Whether the date must belong to the opening label of a PHP string
     * @return bool Whether a label has a valid calendar date before the cutoff
     */
    private function hasOldLabel(string $text, bool $atStart = false): bool
    {
        $pattern = ($atStart ? '/^' : '/') . self::LABEL . '/';
        preg_match_all($pattern, $text, $matches);
        foreach ($matches[1] as $date) {
            [$year, $month, $day] = str_contains($date, '.')
                ? array_reverse(explode('.', $date))
                : explode('-', $date);
            if (checkdate((int)$month, (int)$day, (int)$year) && "$year-$month-$day" < self::CUTOFF) {
                return true;
            }
        }

        return false;
    }
}
