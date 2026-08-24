<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

/**
 * Outcome of pressing the baseline update button: the file to write, if writing is
 * allowed at all, and the whole failure message the guard test reports.
 *
 * The button only shrinks the debt, so an update routinely lands with records it
 * refused to touch; those refusals are the reason the message exists, and the reason
 * the outcome is a value of its own rather than a plain string.
 */
final class BaselineUpdate
{
    private const string REWRITTEN = 'Baseline regenerated from the current tree — review the diff before committing it.';

    private const string WITHHELD = 'The update mode only shrinks the debt, so %d record(s) below stayed as they were.'
        . ' Fix the lines, or raise the count by hand: written by a person, the growth shows up in the diff as a decision.';

    private const string REFUSED = 'Baseline left untouched: its own records must be readable before the tree is written'
        . ' into them. Fix the lines below by hand.';

    /**
     * @param string|null $text Baseline file contents, or null when the file must not be written
     * @param array<int, array<int, string>> $withheld One block of lines per record the update refused to write
     * @param array<int, string> $problems Reasons the baseline could not be written at all
     */
    private function __construct(
        private readonly ?string $text,
        private readonly array $withheld,
        private readonly array $problems,
    ) {
    }

    /**
     * The button did its work: the file is written, and every record it could not
     * shrink is named in the message.
     *
     * @param string $text Baseline file contents to write
     * @param array<int, array<int, string>> $withheld One block of lines per record left as it was
     * @return self Outcome carrying the new file
     */
    public static function rewritten(string $text, array $withheld): self
    {
        return new self($text, $withheld, []);
    }

    /**
     * The button did nothing: the baseline cannot be read, so it cannot be rewritten
     * without losing the records nobody could parse.
     *
     * @param array<int, string> $problems Records rejected while reading the file
     * @return self Outcome carrying no file
     */
    public static function refused(array $problems): self
    {
        return new self(null, [], $problems);
    }

    /**
     * @return string|null Baseline file contents, or null when nothing may be written
     */
    public function text(): ?string
    {
        return $this->text;
    }

    /**
     * @return string Whole failure message of the update run, refusals included
     */
    public function message(): string
    {
        if ($this->text === null) {
            return implode("\n", array_merge([self::REFUSED], $this->problems));
        }
        if ($this->withheld === []) {
            return self::REWRITTEN;
        }

        return implode("\n", array_merge(
            [self::REWRITTEN, sprintf(self::WITHHELD, count($this->withheld))],
            array_merge(...$this->withheld),
        ));
    }
}
