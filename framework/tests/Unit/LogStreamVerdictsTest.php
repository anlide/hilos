<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\LogStreamConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Log\DaemonRawStream;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/log-stream-verdicts.php';

/**
 * The parts of the log-stream check that answer without a stand: how a stream token resolves,
 * what a record is judged against a snapshot written by hand, the table's own rules, and that
 * the table in the repository keeps them.
 *
 * Standing a demo up, provoking a source and reading docker are deliberately out of scope —
 * that is what the `log-streams` step of the full run does, and proving it here would mean doing
 * it here. What is worth pinning is the judging, because its mistakes are invisible on a green
 * stand: a token that quietly matched an agent's `.error.log` twin as well, a `never` that was
 * satisfied by the stream not existing, a table whose empty `never` reads as a typo and as a
 * claim at once.
 *
 * The file under test is a plain script rather than a class, so it is required by path, the same
 * way `scripts/check-log-streams.php` requires it. The table is loaded the same way, once, in the
 * one test that judges it.
 */
final class LogStreamVerdictsTest extends TestCase
{
    /** A snapshot of a settled stand: both container streams, the daemon's files and one worker of each type. */
    private const array SETTLED = [
        'container-log' => "[stamp] Docker watchdog started\n[stamp] Starting daemon process...\n",
        'container-log-stderr' => '',
        'daemon.log' => "[stamp] Daemon started with epoll\n[stamp] Worker #1 started [type=regular]\n",
        'daemon-error.log' => '',
        'daemon-raw.log' => '',
        'daemon-error-raw.log' => '',
        'worker-regular-1.log' => "[stamp] Worker #1 started\n[stamp] Connected to daemon\n",
        'worker-monopolistic-2.log' => "[stamp] Worker #2 started\n[stamp] Connected to daemon\n",
        'agent-hilos_logs.log' => "[stamp] [INFO] Agent started 'hilos_logs'\n",
        'agent-hilos_logs.error.log' => '',
    ];

    /** A record in good standing, the shape every rule test bends one key of. */
    private const array SOUND = [
        'rows' => [5],
        'scenario' => 'alive',
        'source' => 'master INFO goes to daemon.log only',
        'pattern' => '/Daemon started with epoll/',
        'lands' => ['daemon.log'],
        'never' => ['container-log'],
        'nowhere' => false,
        'empty' => [],
    ];

    public function testContainerTokenResolvesToItselfOnlyWhenTheSnapshotHoldsIt(): void
    {
        self::assertSame(['container-log'], resolveLogStreamToken('container-log', self::SETTLED));
        self::assertSame([], resolveLogStreamToken('container-log', ['daemon.log' => '']));
    }

    public function testFileTokenResolvesByNameAndAWildcardDoesNotCrossADot(): void
    {
        self::assertSame(['daemon.log'], resolveLogStreamToken('daemon.log', self::SETTLED));
        self::assertSame(['worker-regular-1.log'], resolveLogStreamToken('worker-regular-*.log', self::SETTLED));
        self::assertSame(['agent-hilos_logs.log'], resolveLogStreamToken('agent-*.log', self::SETTLED));
        self::assertSame(['agent-hilos_logs.error.log'], resolveLogStreamToken('agent-*.error.log', self::SETTLED));
        self::assertSame([], resolveLogStreamToken('worker-regular-*.error.log', self::SETTLED));
    }

    public function testAnyFileResolvesToEveryFileAndToNeitherContainerStream(): void
    {
        $names = resolveLogStreamToken('any-file', self::SETTLED);

        self::assertNotContains('container-log', $names);
        self::assertNotContains('container-log-stderr', $names);
        self::assertCount(count(self::SETTLED) - 2, $names);
    }

    public function testASatisfiedRecordEarnsNoVerdict(): void
    {
        self::assertSame([], judgeLogStreamRecord(self::SOUND, self::SETTLED));
    }

    public function testALineThatHasNotLandedIsReportedByItsToken(): void
    {
        $record = ['pattern' => '/Daemon stopped/'] + self::SOUND;

        $verdicts = judgeLogStreamRecord($record, self::SETTLED);

        self::assertCount(1, $verdicts);
        self::assertSame('daemon.log', $verdicts[0]['stream']);
        self::assertStringStartsWith('expected in daemon.log, not there', $verdicts[0]['reason']);
    }

    public function testALandingTokenThatResolvesToNoStreamHasNotLanded(): void
    {
        $record = ['lands' => ['worker-regular-*.error.log']] + self::SOUND;

        $verdicts = judgeLogStreamRecord($record, self::SETTLED);

        self::assertCount(1, $verdicts);
        self::assertSame('expected in worker-regular-*.error.log, no such stream', $verdicts[0]['reason']);
    }

    public function testALandingIsSatisfiedByAnyOneStreamOfAFamily(): void
    {
        $record = ['pattern' => '/Connected to daemon/', 'lands' => ['worker-regular-*.log'], 'never' => ['daemon.log']] + self::SOUND;
        $snapshot = ['worker-regular-3.log' => "[stamp] Worker #3 started\n"] + self::SETTLED;

        self::assertSame([], judgeLogStreamRecord($record, $snapshot));
    }

    public function testAForbiddenLineIsReportedInEveryStreamOfAFamilyWithItsLineNumber(): void
    {
        $record = ['pattern' => '/Connected to daemon/', 'lands' => ['worker-regular-*.log'], 'never' => ['worker-monopolistic-*.log']]
            + self::SOUND;
        $snapshot = ['worker-monopolistic-4.log' => "[stamp] Connected to daemon\n"] + self::SETTLED;

        $verdicts = judgeLogStreamRecord($record, $snapshot);

        self::assertCount(2, $verdicts);
        self::assertSame('worker-monopolistic-4.log', $verdicts[0]['stream']);
        self::assertSame(1, $verdicts[0]['line']);
        self::assertSame('worker-monopolistic-2.log', $verdicts[1]['stream']);
        self::assertSame(2, $verdicts[1]['line']);
        self::assertSame('forbidden in worker-monopolistic-2.log, found at line 2', $verdicts[1]['reason']);
    }

    public function testAForbiddenStreamThatDoesNotExistForbidsNothing(): void
    {
        $record = ['never' => ['worker-regular-*.error.log']] + self::SOUND;

        self::assertSame([], judgeLogStreamRecord($record, self::SETTLED));
    }

    public function testTheLandingHalfIgnoresTheForbiddenHalfSoItCanBePolledEarly(): void
    {
        $record = ['never' => ['daemon.log']] + self::SOUND;

        self::assertSame([], judgeLogStreamLandings($record, self::SETTLED));
        self::assertCount(1, judgeLogStreamRecord($record, self::SETTLED));
    }

    public function testANowhereRecordLandsNothingAndForbidsEverywhereItNames(): void
    {
        $record = ['pattern' => '/Connected to daemon/', 'lands' => [], 'nowhere' => true, 'never' => ['any-file']] + self::SOUND;

        $verdicts = judgeLogStreamRecord($record, self::SETTLED);

        self::assertSame([], judgeLogStreamLandings($record, self::SETTLED));
        self::assertSame(['worker-regular-1.log', 'worker-monopolistic-2.log'], array_column($verdicts, 'stream'));
    }

    public function testAnAnchorMeansTheStartOfALineAndNotOfTheStream(): void
    {
        $record = ['pattern' => '/^Fatal error: /', 'lands' => ['daemon-raw.log'], 'never' => ['container-log']] + self::SOUND;
        $snapshot = [
            'daemon-raw.log' => "\nFatal error: Allowed memory size exhausted\n",
            'container-log' => "[stamp] ERROR: stopped. Last daemon output: daemon-raw.log: Fatal error: Allowed memory size exhausted\n",
        ] + self::SETTLED;

        self::assertSame([], judgeLogStreamRecord($record, $snapshot));
    }

    public function testAnEmptinessRecordWantsTheFilePresentAndAtZeroBytes(): void
    {
        $record = ['pattern' => null, 'lands' => [], 'never' => [], 'empty' => ['daemon-raw.log', 'daemon-error-raw.log']] + self::SOUND;

        self::assertSame([], judgeLogStreamRecord($record, self::SETTLED));

        $missing = judgeLogStreamRecord($record, array_diff_key(self::SETTLED, ['daemon-raw.log' => '']));
        self::assertSame(['expected present and empty: daemon-raw.log is missing'], array_column($missing, 'reason'));

        $grown = judgeLogStreamRecord($record, ['daemon-error-raw.log' => 'PHP Warning'] + self::SETTLED);
        self::assertSame(['expected empty: daemon-error-raw.log holds 11 byte(s)'], array_column($grown, 'reason'));
    }

    public function testTheRedTextNamesTheRowsTheReasonAndTheTailOfEveryStreamTheRecordNames(): void
    {
        $record = ['rows' => [2, 17], 'pattern' => '/Daemon stopped/', 'lands' => ['daemon.log'], 'never' => ['container-log']] + self::SOUND;
        $verdict = judgeLogStreamRecord($record, self::SETTLED)[0];

        $text = renderLogStreamFailure($verdict, self::SETTLED);

        self::assertStringStartsWith('RED map row 2+17, scenario alive: master INFO goes to daemon.log only', $text);
        self::assertStringContainsString("pattern: /Daemon stopped/\n", $text);
        self::assertStringContainsString('expected in daemon.log, not there', $text);
        self::assertStringContainsString("--- tail of daemon.log (", $text);
        self::assertStringContainsString("| [stamp] Worker #1 started [type=regular]\n", $text);
        self::assertStringContainsString("--- tail of container-log (", $text);
        self::assertStringNotContainsString('worker-regular-1.log', $text);
    }

    public function testTheTableInTheRepositoryKeepsItsOwnRules(): void
    {
        $records = require __DIR__ . '/../../../scripts/log-streams.php';

        self::assertSame([], validateLogStreamRecords($records));
        self::assertSame([1, 2, 3, 5, 6, 8, 10, 11, 13, 14, 15, 17, 19, 23], logStreamRowsCovered($records));
        foreach (LOG_STREAM_SCENARIOS as $scenario) {
            self::assertNotSame([], logStreamRecordsOf($records, $scenario), $scenario . ' has no record');
        }
    }

    public function testARecordWithAPatternMustNameSomewhereTheLineMustNotBe(): void
    {
        $problems = validateLogStreamRecords([['never' => []] + self::SOUND]);

        self::assertCount(1, $problems);
        self::assertStringContainsString('never is empty', $problems[0]);
    }

    public function testLandsMayBeEmptyOnlyUnderNowhereAndNotTogetherWithIt(): void
    {
        self::assertStringContainsString('lands is empty without nowhere', validateLogStreamRecords([['lands' => []] + self::SOUND])[0]);
        self::assertStringContainsString('cannot both be set', validateLogStreamRecords([['nowhere' => true] + self::SOUND])[0]);
        self::assertSame([], validateLogStreamRecords([['lands' => [], 'nowhere' => true] + self::SOUND]));
    }

    public function testARecordWithoutAPatternAssertsEmptinessAndNothingElse(): void
    {
        $bare = ['pattern' => null, 'lands' => [], 'never' => []] + self::SOUND;
        self::assertStringContainsString('asserts nothing', validateLogStreamRecords([$bare])[0]);

        $mixed = ['pattern' => null, 'empty' => ['daemon-raw.log']] + self::SOUND;
        self::assertStringContainsString('nothing to apply to', validateLogStreamRecords([$mixed])[0]);

        self::assertSame([], validateLogStreamRecords([['empty' => ['daemon-raw.log']] + $bare]));
    }

    public function testEveryTokenMustBeInTheVocabularyAndAnyFileOnlyInNever(): void
    {
        self::assertStringContainsString('not a stream token', validateLogStreamRecords([['lands' => ['daemon.txt']] + self::SOUND])[0]);
        self::assertStringContainsString('legal only in never', validateLogStreamRecords([['lands' => ['any-file']] + self::SOUND])[0]);
        self::assertStringContainsString('not one concrete file', validateLogStreamRecords([['empty' => ['agent-*.log']] + self::SOUND])[0]);
        self::assertStringContainsString('not one concrete file', validateLogStreamRecords([['empty' => ['container-log']] + self::SOUND])[0]);
    }

    public function testRowsScenarioSourceAndPatternAreHeldToTheMap(): void
    {
        self::assertStringContainsString('names no map row', validateLogStreamRecords([['rows' => []] + self::SOUND])[0]);
        self::assertStringContainsString('outside 1..23', validateLogStreamRecords([['rows' => [24]] + self::SOUND])[0]);
        self::assertStringContainsString('is not one of', validateLogStreamRecords([['scenario' => 'idle'] + self::SOUND])[0]);
        self::assertStringContainsString('does not compile', validateLogStreamRecords([['pattern' => '/(/'] + self::SOUND])[0]);
        self::assertStringContainsString('source is not unique', validateLogStreamRecords([self::SOUND, self::SOUND])[0]);
    }

    public function testAMissingOrUnknownKeyIsReportedBeforeAnythingElse(): void
    {
        $problems = validateLogStreamRecords([array_diff_key(self::SOUND, ['nowhere' => true]) + ['extra' => 1]]);

        self::assertSame(['record #0: missing key nowhere', 'record #0: unknown key extra'], $problems);
    }

    public function testSharedWorkerIndexesAreTheOnesBothTypesWrote(): void
    {
        self::assertSame([], logStreamSharedWorkerIndexes(self::SETTLED));
        self::assertSame([1], logStreamSharedWorkerIndexes(['worker-monopolistic-1.log' => ''] + self::SETTLED));
    }

    public function testTheVocabularyIsDerivedFromTheFrameworkNamesAndNotSpelledApart(): void
    {
        $raw = static fn(string $logger): string => basename(DaemonRawStream::pathFor('/var/log/hilos/' . $logger));
        $worker = static fn(string $type): string => LogStreamConstants::WORKER_STREAM_PREFIX . $type
            . LogStreamConstants::WORKER_TYPE_SEPARATOR . '*';

        self::assertSame([
            'daemon.log',
            'daemon-error.log',
            $raw('daemon.log'),
            $raw('daemon-error.log'),
            $worker(WorkerConstants::TYPE_REGULAR) . LogStreamConstants::STREAM_SUFFIX,
            $worker(WorkerConstants::TYPE_REGULAR) . LogStreamConstants::ERROR_STREAM_SUFFIX,
            $worker(WorkerConstants::TYPE_MONOPOLISTIC) . LogStreamConstants::STREAM_SUFFIX,
            $worker(WorkerConstants::TYPE_MONOPOLISTIC) . LogStreamConstants::ERROR_STREAM_SUFFIX,
            LogStreamConstants::AGENT_STREAM_PREFIX . '*' . LogStreamConstants::STREAM_SUFFIX,
            LogStreamConstants::AGENT_STREAM_PREFIX . '*' . LogStreamConstants::ERROR_STREAM_SUFFIX,
        ], LOG_STREAM_FILE_TOKENS);

        $records = require __DIR__ . '/../../../scripts/log-streams.php';
        $markerPatterns = array_column(
            array_filter($records, static fn(array $record): bool => str_contains($record['source'], 'marker')),
            'pattern',
        );
        self::assertSame(['/' . preg_quote(Logger::AGENT_LOG_MARKER, '/') . '/'], $markerPatterns);
    }
}
