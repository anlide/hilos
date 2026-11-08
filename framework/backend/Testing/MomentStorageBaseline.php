<?php

declare(strict_types=1);

namespace Hilos\Testing;

use stdClass;

/** Exact historical moment debt at a JSON/report boundary, private to one reconciliation. */
final class MomentStorageBaseline
{
    private const int VERSION = 1;
    private const int SUBJECT_FIELDS = 4;
    private const int FINDING_FIELDS = 3;
    private const int SOURCE_SINK_INDEX = 3;
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const string SCHEMA = 'schema';
    private const string SOURCE = 'source';
    private const string TYPE = 'MOMENT-TYPE';
    private const string AUTO_WRITE = 'MOMENT-AUTO-WRITE';
    private const string WRITE = 'MOMENT-WRITE';
    private const array RECORD_KEYS = ['actual', 'count', 'owner', 'rule', 'subject'];
    private const array OWNERS = [
        'HIL-1918', 'HIL-1919', 'HIL-1920', 'HIL-1921', 'HIL-1922', 'HIL-1923', 'HIL-1924',
        'HIL-1925', 'HIL-1926', 'HIL-1927', 'HIL-1928', 'HIL-1929', 'HIL-1930',
    ];

    /**
     * @param array<string, int> $allowances Counts keyed by canonical finding tuple
     * @param array<string, string> $owners Migration owners keyed by finding
     * @param array<string, string> $recordSubjects Canonical subjects keyed by finding
     * @param list<string> $parseProblems Invalid registry diagnostics; any problem disables all allowances
     */
    private function __construct(
        private readonly array $allowances,
        private readonly array $owners,
        private readonly array $recordSubjects,
        private readonly array $parseProblems,
    ) {
    }

    /**
     * Malformed registries carry diagnostics rather than granting partial exemptions.
     *
     * @param string $json Versioned registry JSON, with subjects encoded as JSON tuple strings
     * @return self Parsed registry; ordinary invalid input never throws
     */
    public static function fromJson(string $json): self
    {
        $registry = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new self([], [], [], ['moment storage baseline JSON is malformed']);
        }
        if (!$registry instanceof stdClass || !self::hasKeys($registry, ['records', 'version'])) {
            return new self([], [], [], ['moment storage baseline must contain exactly version and records']);
        }
        if ($registry->version !== self::VERSION) {
            return new self([], [], [], ['moment storage baseline version must be integer 1']);
        }
        if (!is_array($registry->records) || !array_is_list($registry->records)) {
            return new self([], [], [], ['moment storage baseline records must be a list']);
        }

        $allowances = [];
        $owners = [];
        $subjects = [];
        $problems = [];
        foreach ($registry->records as $index => $record) {
            $label = sprintf('moment storage baseline record %d', $index + 1);
            if (!$record instanceof stdClass || !self::hasKeys($record, self::RECORD_KEYS)) {
                $problems[] = $label . ' must contain exactly rule, subject, actual, count and owner';
                continue;
            }

            $key = self::canonicalFinding($record->rule, $record->subject, $record->actual);
            if ($key === null) {
                $problems[] = $label . ' has an invalid rule, subject or actual';
            }
            if (!is_int($record->count) || $record->count < 1) {
                $problems[] = $label . ' count must be a positive integer';
            }
            if (!is_string($record->owner) || !in_array($record->owner, self::OWNERS, true)) {
                $problems[] = $label . ' owner must be HIL-1918 through HIL-1930';
            }
            if ($key === null || !is_int($record->count) || $record->count < 1
                || !is_string($record->owner) || !in_array($record->owner, self::OWNERS, true)) {
                continue;
            }
            if (isset($allowances[$key])) {
                $problems[] = $label . ' duplicates finding ' . $key;
                continue;
            }

            $allowances[$key] = $record->count;
            $owners[$key] = $record->owner;
            $subjects[$key] = self::canonicalSubject($record->subject);
        }

        return $problems === []
            ? new self($allowances, $owners, $subjects, [])
            : new self([], [], [], $problems);
    }

    /**
     * Includes old subjects so a successful complete-domain scan can cover deleted sinks too.
     *
     * @return list<string> Unique canonical subjects in lexical order; empty for an invalid registry
     */
    public function subjects(): array
    {
        $subjects = array_values(array_unique($this->recordSubjects));
        sort($subjects, SORT_STRING);
        return $subjects;
    }

    /**
     * Coverage alone authorizes stale-record checks. A failed or partial scan must not claim its whole domain.
     * Canonical JSON uses JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE without whitespace.
     *
     * @param array<string, list<string>> $reported Diagnostic occurrences keyed by canonical [rule, subject, actual] JSON
     * @param list<string> $coveredSubjects Explicitly checked subjects, including old subjects in a fully scanned domain
     * @return list<string> Registry/input errors, uncovered occurrences, then covered stale records in lexical key order
     */
    public function reconcile(array $reported, array $coveredSubjects): array
    {
        $problems = $this->parseProblems;
        $covered = [];
        $validInput = true;
        foreach ($coveredSubjects as $subject) {
            $canonical = self::canonicalSubject($subject);
            if ($canonical === null) {
                $problems[] = 'moment storage coverage contains an invalid subject';
                $validInput = false;
                continue;
            }
            $covered[$canonical] = true;
        }

        ksort($reported, SORT_STRING);
        $counts = [];
        foreach ($reported as $key => $occurrences) {
            $tuple = is_string($key) ? json_decode($key) : null;
            if (!is_array($tuple) || !array_is_list($tuple) || count($tuple) !== self::FINDING_FIELDS
                || self::canonicalFinding(...$tuple) !== $key) {
                $problems[] = sprintf('moment storage report key is not a canonical finding: %s', $key);
                $validInput = false;
                continue;
            }
            if (!is_array($occurrences) || !array_is_list($occurrences)
                || array_any($occurrences, static fn(mixed $line): bool => !is_string($line))) {
                $problems[] = 'moment storage report occurrences must be a list of strings: ' . $key;
                $validInput = false;
                continue;
            }

            $counts[$key] = count($occurrences);
            array_push($problems, ...array_slice($occurrences, $this->allowances[$key] ?? 0));
        }

        if (!$validInput) {
            return $problems;
        }
        $allowances = $this->allowances;
        ksort($allowances, SORT_STRING);
        foreach ($allowances as $key => $allowance) {
            if (!isset($covered[$this->recordSubjects[$key]])) {
                continue;
            }
            $left = $counts[$key] ?? 0;
            if ($left === 0) {
                $problems[] = sprintf('moment storage baseline record %s (%s) is paid off — delete the record', $key, $this->owners[$key]);
            } elseif ($left < $allowance) {
                $problems[] = sprintf(
                    'moment storage baseline record %s (%s) allows %d, only %d left — lower the count',
                    $key,
                    $this->owners[$key],
                    $allowance,
                    $left,
                );
            }
        }
        return $problems;
    }

    /**
     * @param stdClass $record JSON object whose complete field set is checked
     * @param list<string> $expected Sorted allowed keys
     * @return bool Whether all and only the expected keys exist
     */
    private static function hasKeys(stdClass $record, array $expected): bool
    {
        $keys = array_keys(get_object_vars($record));
        sort($keys, SORT_STRING);
        return $keys === $expected;
    }

    /**
     * @param mixed $rule Closed rule name from the boundary
     * @param mixed $subject Encoded semantic subject
     * @param mixed $actual Physical representation or lowercase SHA-256 fingerprint
     * @return ?string Canonical finding key, or null for an invalid finding
     */
    private static function canonicalFinding(mixed $rule, mixed $subject, mixed $actual): ?string
    {
        $canonical = self::canonicalSubject($subject);
        if ($canonical === null || !is_string($actual) || trim($actual) === '') {
            return null;
        }
        $tuple = json_decode($canonical);
        if ($tuple[0] === self::SCHEMA) {
            if (!in_array($rule, [self::TYPE, self::AUTO_WRITE], true)) {
                return null;
            }
        } elseif ($rule !== self::WRITE || preg_match('/\A[0-9a-f]{64}\z/', $actual) !== 1) {
            return null;
        }
        return json_encode([$rule, $canonical, $actual], self::JSON_FLAGS);
    }

    /**
     * The source tuple's last member is an encoded schema subject, preserving the same sink identity.
     * Names are strings even when they contain only digits; no class loading or semantic guessing occurs.
     *
     * @param mixed $subject JSON tuple string from a record, finding or coverage claim
     * @param bool $schemaOnly Whether a source's sink requires a schema tuple
     * @return ?string Canonical subject, or null for invalid structure
     */
    private static function canonicalSubject(mixed $subject, bool $schemaOnly = false): ?string
    {
        if (!is_string($subject)) {
            return null;
        }
        $tuple = json_decode($subject);
        if (!is_array($tuple) || !array_is_list($tuple) || count($tuple) !== self::SUBJECT_FIELDS
            || array_any($tuple, static fn(mixed $part): bool => !is_string($part) || trim($part) === '')) {
            return null;
        }
        if ($tuple[0] === self::SCHEMA) {
            return json_encode($tuple, self::JSON_FLAGS);
        }
        if ($schemaOnly || $tuple[0] !== self::SOURCE) {
            return null;
        }
        $sink = self::canonicalSubject($tuple[self::SOURCE_SINK_INDEX], true);
        if ($sink === null || str_contains($tuple[1], '\\') || str_starts_with($tuple[1], '/')
            || preg_match('~(?:\A|/)\.\.?(?:/|\z)|\A[A-Za-z]:~', $tuple[1]) === 1
            || preg_match('/\A[^\s:]+::[^\s:]+\z/', $tuple[2]) !== 1) {
            return null;
        }
        $tuple[self::SOURCE_SINK_INDEX] = $sink;
        return json_encode($tuple, self::JSON_FLAGS);
    }
}
