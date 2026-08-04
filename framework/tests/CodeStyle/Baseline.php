<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

/**
 * Known code-style debt, anchored to a file and a count rather than to line
 * numbers: any edit above a violation shifts its line, while the count survives.
 *
 * Every record must name the leaf that will remove it, so the baseline reads as a
 * list of owed work instead of a silent mute list. It can only shrink: a record
 * that outgrows its count, shrinks below it, or has nothing left to cover is
 * reported the same way a fresh violation is.
 */
final class Baseline
{
    public const string PATH = 'framework/tests/CodeStyle/baseline.txt';

    /** Written for a record the update mode cannot attribute; fails the next run on purpose. */
    public const string UNKNOWN_TICKET = 'TODO-TICKET';

    private const string HEADER = <<<'TEXT'
        # Known code-style debt, one record per rule and file:
        #     <RULE-ID> <path from repository root> <count> # <HIL-nnn>
        # The ticket is mandatory: it names the leaf that removes the record.
        # Regenerate with CODESTYLE_BASELINE_UPDATE=1 on the framework unit run.
        TEXT;

    /**
     * @param array<string, int> $allowances Allowed count keyed by "<rule id> <path>"
     * @param array<string, string> $tickets Owing leaf keyed by "<rule id> <path>"
     * @param array<int, string> $parseProblems Records rejected while reading the file
     */
    private function __construct(
        private readonly array $allowances,
        private readonly array $tickets,
        private readonly array $parseProblems,
    ) {
    }

    /**
     * @param string $text Raw baseline file contents; empty text means no known debt
     * @return self Parsed baseline, carrying the problems of its own malformed records
     */
    public static function fromText(string $text): self
    {
        $allowances = [];
        $tickets = [];
        $problems = [];

        foreach (explode("\n", $text) as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = preg_split('/\s+/', $line) ?: [];
            if (count($fields) !== 5 || $fields[3] !== '#' || !ctype_digit($fields[2])) {
                $problems[] = sprintf('baseline line %d is malformed: %s', $index + 1, $line);
                continue;
            }
            if (preg_match('/^HIL-\d+$/', $fields[4]) !== 1) {
                $problems[] = sprintf('baseline record "%s %s" names no owing leaf: %s', $fields[0], $fields[1], $fields[4]);
                continue;
            }

            $key = $fields[0] . ' ' . $fields[1];
            $allowances[$key] = (int)$fields[2];
            $tickets[$key] = $fields[4];
        }

        return new self($allowances, $tickets, $problems);
    }

    /**
     * Everything the guard test must report: violations above the allowed count,
     * then the bookkeeping the baseline itself owes.
     *
     * @param array<string, array<int, string>> $reported Violation lines keyed by "<rule id> <path>"
     * @return array<int, string> Problems, ordered by record
     */
    public function reconcile(array $reported): array
    {
        ksort($reported);
        $problems = $this->parseProblems;

        foreach ($reported as $key => $lines) {
            $allowance = $this->allowances[$key] ?? 0;
            if (count($lines) > $allowance) {
                array_push($problems, ...array_slice($lines, $allowance));
            }
        }

        $allowances = $this->allowances;
        ksort($allowances);
        foreach ($allowances as $key => $allowance) {
            $left = count($reported[$key] ?? []);
            if ($left === 0) {
                $problems[] = sprintf('baseline record "%s" is paid off — delete the line', $key);
                continue;
            }
            if ($left < $allowance) {
                $problems[] = sprintf('baseline record "%s" allows %d, only %d left — lower the count', $key, $allowance, $left);
            }
        }

        return $problems;
    }

    /**
     * Rewrites the baseline against what the scan actually found, keeping the owing
     * leaf of every record that survives.
     *
     * @param array<string, array<int, string>> $reported Violation lines keyed by "<rule id> <path>"
     * @return string Baseline file contents
     */
    public function render(array $reported): string
    {
        ksort($reported);
        $lines = [self::HEADER];
        foreach ($reported as $key => $violations) {
            $lines[] = sprintf('%s %d # %s', $key, count($violations), $this->tickets[$key] ?? self::UNKNOWN_TICKET);
        }

        return implode("\n", $lines) . "\n";
    }
}
