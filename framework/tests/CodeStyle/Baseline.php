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
     * @param list<string> $sourceLines Original lines including their line endings
     * @param array<int, string> $keysByLine Parsed record key at each source line
     */
    private function __construct(
        private readonly array $allowances,
        private readonly array $tickets,
        private readonly array $parseProblems,
        private readonly array $sourceLines,
        private readonly array $keysByLine,
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
        $sourceLines = preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keysByLine = [];

        foreach ($sourceLines as $index => $line) {
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
            $keysByLine[$index] = $key;
        }

        return new self($allowances, $tickets, $problems, $sourceLines, $keysByLine);
    }

    /**
     * Everything the guard test must report: violations above the allowed count,
     * then the bookkeeping the baseline itself owes.
     *
     * @param array<string, array<int, string>> $reported Violation lines keyed by "<rule id> <path>"
     * @param BaselineScope|null $scope Owned records, or null for the entire baseline
     * @param bool $reportParseProblems Whether this is the piece that reports file parsing errors
     * @return array<int, string> Problems, ordered by record
     */
    public function reconcile(array $reported, ?BaselineScope $scope = null, bool $reportParseProblems = true): array
    {
        ksort($reported);
        $problems = $reportParseProblems ? $this->parseProblems : [];

        foreach ($reported as $key => $lines) {
            if ($scope !== null && !$scope->contains($key)) {
                continue;
            }
            $allowance = $this->allowances[$key] ?? 0;
            if (count($lines) > $allowance) {
                array_push($problems, ...array_slice($lines, $allowance));
            }
        }

        $allowances = $this->allowances;
        ksort($allowances);
        foreach ($allowances as $key => $allowance) {
            if ($scope !== null && !$scope->contains($key)) {
                continue;
            }
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
     * The parser piece also catches records whose rule or path no guard piece owns.
     * Splitting a scan must not silently exempt debt outside the declared scopes.
     *
     * @param list<BaselineScope> $scopes Every guard piece's record boundary
     * @return list<string> Parse errors and baseline records with no guard owner
     */
    public function reconcileUnowned(array $scopes): array
    {
        $problems = $this->parseProblems;
        $allowances = $this->allowances;
        ksort($allowances);
        foreach ($allowances as $key => $allowance) {
            foreach ($scopes as $scope) {
                if ($scope->contains($key)) {
                    continue 2;
                }
            }
            $problems[] = sprintf('baseline record "%s" has no guard owner — delete or correct the line', $key);
        }

        return $problems;
    }

    /**
     * Rewrites the baseline against what the scan actually found, keeping the owing
     * leaf of every record that survives.
     *
     * The rewrite only shrinks the debt: a known record is written at the lower of
     * its count and the tree, a record with nothing left disappears, and a key the
     * baseline never knew is not written at all. Growth stays for a person to write
     * by hand, where it reads as a decision instead of a side effect of the button.
     *
     * @param array<string, array<int, string>> $reported Violation lines keyed by "<rule id> <path>"
     * @param BaselineScope|null $scope Owned records; other source lines are preserved verbatim
     * @param bool $reportParseProblems Whether this is the piece that reports file parsing errors
     * @return BaselineUpdate File to write and the message the run reports
     */
    public function update(array $reported, ?BaselineScope $scope = null, bool $reportParseProblems = true): BaselineUpdate
    {
        if ($this->parseProblems !== []) {
            return BaselineUpdate::refused($reportParseProblems ? $this->parseProblems : []);
        }

        ksort($reported);
        $rewritten = [];
        $withheld = [];
        foreach ($reported as $key => $violations) {
            if ($scope !== null && !$scope->contains($key)) {
                continue;
            }
            $found = count($violations);
            $allowance = $this->allowances[$key] ?? null;
            if ($allowance === null) {
                $withheld[] = $this->withhold(
                    sprintf('%s: not written, the tree has %d — the update mode never adds a record', $key, $found),
                    $violations,
                );
                continue;
            }
            if ($found > $allowance) {
                $withheld[] = $this->withhold(
                    sprintf(
                        '%s: kept at %d, the tree has %d — the update mode never raises a count',
                        $key,
                        $allowance,
                        $found,
                    ),
                    array_slice($violations, $allowance),
                );
            }

            $written = min($found, $allowance);
            if ($written === 0) {
                continue;
            }

            $rewritten[$key] = sprintf('%s %d # %s', $key, $written, $this->tickets[$key]) . "\n";
        }

        if ($scope === null) {
            return BaselineUpdate::rewritten(self::HEADER . "\n" . implode('', $rewritten), $withheld);
        }

        $lines = $this->sourceLines;
        foreach ($this->keysByLine as $index => $key) {
            if (!$scope->contains($key)) {
                continue;
            }
            if (isset($rewritten[$key])) {
                $lines[$index] = $rewritten[$key];
                unset($rewritten[$key]);
            } else {
                unset($lines[$index]);
            }
        }

        return BaselineUpdate::rewritten(implode('', $lines), $withheld);
    }

    /**
     * @param string $refusal Why the record was left as it was
     * @param array<int, string> $lines Violation lines the record does not cover
     * @return array<int, string> Refusal with its uncovered lines indented under it
     */
    private function withhold(string $refusal, array $lines): array
    {
        return array_merge([$refusal], array_map(static fn(string $line): string => '  ' . $line, $lines));
    }
}
