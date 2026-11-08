<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Testing\MomentStorageBaseline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic JSON registries pin exact storage debt without admitting production records. */
final class MomentStorageBaselineTest extends TestCase
{
    private const string SCHEMA = '["schema","EventEntity","event","at"]';
    private const string OTHER_SCHEMA = '["schema","EventEntity","event","until"]';
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return iterable<string, array{string}> Every admitted migration owner */
    public static function owners(): iterable
    {
        foreach (range(1918, 1930) as $number) {
            yield 'HIL-' . $number => ['HIL-' . $number];
        }
    }

    /** @param string $owner Named migration leaf */
    #[DataProvider('owners')]
    public function testOnlyExactRecordsAtTheirCountAreSilent(string $owner): void
    {
        $source = self::sourceSubject();
        $baseline = MomentStorageBaseline::fromJson(self::registry([
            self::record(owner: $owner, count: 2),
            self::record(rule: 'MOMENT-AUTO-WRITE', actual: 'DEFAULT current_timestamp()', owner: $owner),
            self::record(rule: 'MOMENT-WRITE', subject: $source, actual: self::HASH, owner: $owner),
        ]));

        self::assertSame([self::SCHEMA, $source], $baseline->subjects());
        self::assertSame([], $baseline->reconcile([
            self::finding('MOMENT-TYPE', self::SCHEMA, 'timestamp') => ['type one', 'type two'],
            self::finding('MOMENT-AUTO-WRITE', self::SCHEMA, 'DEFAULT current_timestamp()') => ['default'],
            self::finding('MOMENT-WRITE', $source, self::HASH) => ['seconds writer'],
        ], $baseline->subjects()));
    }

    public function testNewSubjectsAndCountGrowthReturnEveryUncoveredOccurrence(): void
    {
        $baseline = MomentStorageBaseline::fromJson(self::registry([self::record(count: 1)]));

        self::assertSame(['second copy', 'third copy', 'new column'], $baseline->reconcile([
            self::finding('MOMENT-TYPE', self::SCHEMA, 'timestamp') => ['covered copy', 'second copy', 'third copy'],
            self::finding('MOMENT-TYPE', self::OTHER_SCHEMA, 'timestamp') => ['new column'],
        ], [self::SCHEMA, self::OTHER_SCHEMA]));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> Changes a count-only exemption must never cover */
    public static function replacements(): iterable
    {
        yield 'physical actual' => [self::record(), self::record(actual: 'datetime')];
        yield 'rule' => [self::record(), self::record(rule: 'MOMENT-AUTO-WRITE', actual: 'DEFAULT now()')];
        yield 'declaration' => [self::record(), self::record(subject: '["schema","OtherEntity","event","at"]')];
        yield 'table' => [self::record(), self::record(subject: '["schema","EventEntity","other","at"]')];
        yield 'column' => [self::record(), self::record(subject: self::OTHER_SCHEMA)];

        $sourceRecord = self::record(rule: 'MOMENT-WRITE', subject: self::sourceSubject(), actual: self::HASH);
        yield 'RHS fingerprint' => [$sourceRecord, [...$sourceRecord, 'actual' => str_repeat('b', 64)]];
        yield 'repository path' => [$sourceRecord, [...$sourceRecord, 'subject' => self::sourceSubject(path: 'demo/example/Other.php')]];
        yield 'method' => [$sourceRecord, [...$sourceRecord, 'subject' => self::sourceSubject(method: 'Example\\Writer::update')]];
        yield 'sink' => [$sourceRecord, [...$sourceRecord, 'subject' => self::sourceSubject(sink: self::OTHER_SCHEMA)]];
    }

    /**
     * @param array<string, mixed> $old Historical exact finding
     * @param array<string, mixed> $new Replacement at the same multiplicity
     */
    #[DataProvider('replacements')]
    public function testReplacementAtTheSameCountIsNewDebtAndCoveredOldDebtIsStale(array $old, array $new): void
    {
        $baseline = MomentStorageBaseline::fromJson(self::registry([$old]));
        $oldKey = self::finding($old['rule'], $old['subject'], $old['actual']);

        self::assertSame([
            'replacement occurrence',
            'moment storage baseline record ' . $oldKey . ' (HIL-1918) is paid off — delete the record',
        ], $baseline->reconcile([
            self::finding($new['rule'], $new['subject'], $new['actual']) => ['replacement occurrence'],
        ], [$old['subject'], $new['subject']]));
    }

    public function testShrinkAndPaidOffRecordsRequireExplicitCoverage(): void
    {
        $key = self::finding('MOMENT-TYPE', self::SCHEMA, 'timestamp');
        $baselineJson = self::registry([self::record(count: 3)]);

        self::assertSame([
            'moment storage baseline record ' . $key . ' (HIL-1918) allows 3, only 1 left — lower the count',
        ], MomentStorageBaseline::fromJson($baselineJson)->reconcile([$key => ['remaining']], [self::SCHEMA]));
        self::assertSame([
            'moment storage baseline record ' . $key . ' (HIL-1918) is paid off — delete the record',
        ], MomentStorageBaseline::fromJson($baselineJson)->reconcile([], [self::SCHEMA]));
        self::assertSame([], MomentStorageBaseline::fromJson($baselineJson)->reconcile([], []));
        self::assertSame([], MomentStorageBaseline::fromJson($baselineJson)->reconcile([], [self::OTHER_SCHEMA]));
        self::assertSame([], MomentStorageBaseline::fromJson($baselineJson)->reconcile([$key => ['remaining']], []));
    }

    public function testSubjectsCoverDeletedSourcesWithoutInventingCoverageForInactiveSchemas(): void
    {
        $source = self::sourceSubject();
        $baseline = MomentStorageBaseline::fromJson(self::registry([
            self::record(),
            self::record(rule: 'MOMENT-WRITE', subject: $source, actual: self::HASH, owner: 'HIL-1930'),
        ]));

        self::assertSame([self::SCHEMA, $source], $baseline->subjects());
        self::assertSame([
            'moment storage baseline record ' . self::finding('MOMENT-WRITE', $source, self::HASH)
                . ' (HIL-1930) is paid off — delete the record',
        ], $baseline->reconcile([], [$source]));
    }

    public function testCanonicalTuplesPreserveNumericStringsUnicodeAndDelimiterIdentity(): void
    {
        $baseline = MomentStorageBaseline::fromJson(self::registry([
            self::record(subject: '[ "schema", "0", "01", "0" ]', actual: '0'),
            self::record(subject: '["schema","a|b","c","at"]'),
            self::record(subject: '["schema","a","b|c","at"]'),
            self::record(subject: '["schema","Example\\\\Entity","caf\u00e9","at"]'),
        ]));

        self::assertSame([
            '["schema","0","01","0"]',
            '["schema","Example\\\\Entity","café","at"]',
            '["schema","a","b|c","at"]',
            '["schema","a|b","c","at"]',
        ], $baseline->subjects());
        self::assertSame([], $baseline->reconcile([
            '["MOMENT-TYPE","[\"schema\",\"0\",\"01\",\"0\"]","0"]' => ['numeric string names'],
            '["MOMENT-TYPE","[\"schema\",\"a|b\",\"c\",\"at\"]","timestamp"]' => ['first delimiter tuple'],
            '["MOMENT-TYPE","[\"schema\",\"a\",\"b|c\",\"at\"]","timestamp"]' => ['second delimiter tuple'],
            '["MOMENT-TYPE","[\"schema\",\"Example\\\\\\\\Entity\",\"café\",\"at\"]","timestamp"]' => ['Unicode tuple'],
        ], $baseline->subjects()));
    }

    /** @return iterable<string, array{string, string}> Invalid JSON or root shapes and their exact diagnostics */
    public static function malformedRegistries(): iterable
    {
        yield 'empty text' => ['', 'moment storage baseline JSON is malformed'];
        yield 'syntax' => ['{', 'moment storage baseline JSON is malformed'];
        yield 'trailing data' => ['{"version":1,"records":[]} false', 'moment storage baseline JSON is malformed'];
        foreach (['null', 'false', '1', '"registry"', '[]', '{}', '{"version":1}', '{"records":[]}'] as $json) {
            yield 'shape ' . $json => [$json, 'moment storage baseline must contain exactly version and records'];
        }
        yield 'extra root key' => [
            '{"version":1,"records":[],"extra":true}', 'moment storage baseline must contain exactly version and records',
        ];
        foreach (['2', '"1"', '1.0', 'true', 'null'] as $version) {
            yield 'version ' . $version => [
                '{"version":' . $version . ',"records":[]}', 'moment storage baseline version must be integer 1',
            ];
        }
        foreach (['{}', '{"0":{}}', 'null', '"records"'] as $records) {
            yield 'records ' . $records => [
                '{"version":1,"records":' . $records . '}', 'moment storage baseline records must be a list',
            ];
        }
    }

    /**
     * @param string $json Malformed registry fixture
     * @param string $diagnostic Exact registry refusal
     */
    #[DataProvider('malformedRegistries')]
    public function testMalformedRegistryIsDiagnosticAndNeverCoversDebt(string $json, string $diagnostic): void
    {
        $baseline = MomentStorageBaseline::fromJson($json);

        self::assertSame([], $baseline->subjects());
        self::assertSame([$diagnostic, 'uncovered'], $baseline->reconcile([
            self::finding('MOMENT-TYPE', self::SCHEMA, 'timestamp') => ['uncovered'],
        ], [self::SCHEMA]));
    }

    /** @return iterable<string, array{mixed, list<string>}> Malformed records and all expected field diagnostics */
    public static function malformedRecords(): iterable
    {
        $keysError = 'moment storage baseline record 1 must contain exactly rule, subject, actual, count and owner';
        $findingError = 'moment storage baseline record 1 has an invalid rule, subject or actual';
        foreach ([null, [], 'record', false] as $index => $record) {
            yield 'record type ' . $index => [$record, [$keysError]];
        }
        foreach (array_keys(self::record()) as $key) {
            $record = self::record();
            unset($record[$key]);
            yield 'missing ' . $key => [$record, [$keysError]];
        }
        yield 'extra key' => [[...self::record(), 'extra' => true], [$keysError]];
        foreach ([0, -1, '1', 1.0, true, null, []] as $index => $count) {
            yield 'count ' . $index => [[...self::record(), 'count' => $count], [
                'moment storage baseline record 1 count must be a positive integer',
            ]];
        }
        foreach (['HIL-1917', 'HIL-1931', 'HIL-01918', 'HIL-1918 ', '', null, 1918, []] as $index => $owner) {
            yield 'owner ' . $index => [[...self::record(), 'owner' => $owner], [
                'moment storage baseline record 1 owner must be HIL-1918 through HIL-1930',
            ]];
        }
        foreach (['UNKNOWN', 'MOMENT-COLUMN-MISSING', 'moment-type', null, []] as $index => $rule) {
            yield 'rule ' . $index => [[...self::record(), 'rule' => $rule], [$findingError]];
        }
        foreach (['', ' ', null, 0, true, []] as $index => $actual) {
            yield 'actual ' . $index => [[...self::record(), 'actual' => $actual], [$findingError]];
        }
        foreach ([
            null, [], '', 'event.at', '{}', '["schema","EventEntity","event"]',
            '["schema","EventEntity","event","at","extra"]', '["schema","EventEntity","event",0]',
            '["schema","EventEntity","event",""]', '["unknown","EventEntity","event","at"]',
            '["schema",null,"event","at"]', '{"0":"schema","1":"EventEntity","2":"event","3":"at"}',
        ] as $index => $subject) {
            yield 'subject ' . $index => [[...self::record(), 'subject' => $subject], [$findingError]];
        }
        yield 'schema with source rule' => [self::record(rule: 'MOMENT-WRITE', actual: self::HASH), [$findingError]];
        yield 'source with schema rule' => [self::record(subject: self::sourceSubject()), [$findingError]];
        foreach (['time()', str_repeat('a', 63), str_repeat('A', 64), str_repeat('z', 64)] as $index => $hash) {
            yield 'fingerprint ' . $index => [
                self::record(rule: 'MOMENT-WRITE', subject: self::sourceSubject(), actual: $hash), [$findingError],
            ];
        }
        foreach ([
            self::sourceSubject(sink: self::sourceSubject()), self::sourceSubject(sink: 'event.at'),
            self::sourceSubject(method: 'write'), self::sourceSubject(method: 'Writer::'),
            self::sourceSubject(path: '/absolute.php'), self::sourceSubject(path: '../outside.php'),
            self::sourceSubject(path: 'demo/../outside.php'), self::sourceSubject(path: 'C:/outside.php'),
            self::sourceSubject(path: 'demo\\Writer.php'),
        ] as $index => $subject) {
            yield 'source identity ' . $index => [
                self::record(rule: 'MOMENT-WRITE', subject: $subject, actual: self::HASH), [$findingError],
            ];
        }
        yield 'all invalid fields accumulate' => [[...self::record(), 'rule' => 'UNKNOWN', 'count' => 0, 'owner' => 'later'], [
            $findingError,
            'moment storage baseline record 1 count must be a positive integer',
            'moment storage baseline record 1 owner must be HIL-1918 through HIL-1930',
        ]];
    }

    /**
     * @param mixed $record Deliberately invalid record fixture
     * @param list<string> $diagnostics Exact record refusals
     */
    #[DataProvider('malformedRecords')]
    public function testMalformedRecordsAreRejectedWithoutExceptions(mixed $record, array $diagnostics): void
    {
        $baseline = MomentStorageBaseline::fromJson(self::registry([$record, self::record()]));

        self::assertSame([], $baseline->subjects());
        self::assertSame([...$diagnostics, 'uncovered'], $baseline->reconcile([
            self::finding('MOMENT-TYPE', self::SCHEMA, 'timestamp') => ['uncovered'],
        ], [self::SCHEMA]));
    }

    public function testCanonicalDuplicateTupleIsRejectedEvenWithAnotherOwnerOrCount(): void
    {
        $key = self::finding('MOMENT-TYPE', self::SCHEMA, 'timestamp');
        $baseline = MomentStorageBaseline::fromJson(self::registry([
            self::record(),
            self::record(subject: '[ "schema", "EventEntity", "event", "at" ]', count: 2, owner: 'HIL-1930'),
        ]));

        self::assertSame([], $baseline->subjects());
        self::assertSame([
            'moment storage baseline record 2 duplicates finding ' . $key,
            'uncovered',
        ], $baseline->reconcile([$key => ['uncovered']], [self::SCHEMA]));
    }

    public function testMalformedReportAndCoverageDoNotTurnFailureIntoPaidOffDebt(): void
    {
        $key = self::finding('MOMENT-TYPE', self::SCHEMA, 'timestamp');
        $json = self::registry([self::record()]);

        self::assertSame([
            'moment storage report key is not a canonical finding: not a tuple',
        ], MomentStorageBaseline::fromJson($json)->reconcile(['not a tuple' => ['bad key']], [self::SCHEMA]));
        self::assertSame([
            'moment storage report occurrences must be a list of strings: ' . $key,
        ], MomentStorageBaseline::fromJson($json)->reconcile([$key => [false]], [self::SCHEMA]));
        self::assertSame([
            'moment storage coverage contains an invalid subject',
        ], MomentStorageBaseline::fromJson($json)->reconcile([], ['not a subject', self::SCHEMA]));
        self::assertSame([], MomentStorageBaseline::fromJson('{"version":1,"records":[]}')->reconcile([], []));
    }

    /**
     * @param array<mixed> $records Synthetic registry entries
     * @return string Versioned JSON fixture
     */
    private static function registry(array $records): string
    {
        return json_encode(['version' => 1, 'records' => $records], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param string $rule Exact rule
     * @param string $subject Encoded semantic subject
     * @param string $actual Exact actual representation or fingerprint
     * @param int $count Occurrence multiplicity
     * @param string $owner Migration owner
     * @return array<string, mixed> Complete record fixture
     */
    private static function record(
        string $rule = 'MOMENT-TYPE',
        string $subject = self::SCHEMA,
        string $actual = 'timestamp',
        int $count = 1,
        string $owner = 'HIL-1918',
    ): array {
        return compact('rule', 'subject', 'actual', 'count', 'owner');
    }

    /**
     * @param string $rule Exact rule
     * @param string $subject Canonical encoded subject
     * @param string $actual Exact actual representation or fingerprint
     * @return string Canonical report key
     */
    private static function finding(string $rule, string $subject, string $actual): string
    {
        return json_encode([$rule, $subject, $actual], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param string $path Repository source path
     * @param string $method Declaring class and method
     * @param string $sink Encoded schema subject
     * @return string Canonical source subject fixture
     */
    private static function sourceSubject(
        string $path = 'demo/example/Writer.php',
        string $method = 'Example\\Writer::write',
        string $sink = self::SCHEMA,
    ): string {
        return json_encode(['source', $path, $method, $sink], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
