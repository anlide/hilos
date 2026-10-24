<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouteTally;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DaemonHttpPicture;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterStandingSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterHttpSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterMeasurementsSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Environment\EnvSource;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRulePicture;
use Hilos\DaemonSection\DaemonCronRuleReport;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DTO\DaemonAgentCronSignalData;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterCronSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Hilos;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** The node sends a complete frame at start, on change and periodically. */
final class DaemonNodePictureTest extends TestCase
{
    private const string WARSAW = 'Europe/Warsaw';

    private const string DAILY_AT_THREE = '0 3 * * *';

    private const string CLOCK_FORMAT = 'H:i';

    private const int SECONDS_PER_DAY = 86400;

    private ?ClusterContext $previousCluster = null;
    private ?SignalRouter $previousRouter = null;
    private ?EnvAccessor $previousEnv = null;
    private string|false $previousProjectZone = false;
    private string $previousProcessZone = TimeConstants::UTC_TIME_ZONE;

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        $this->previousProjectZone = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        $this->previousProcessZone = date_default_timezone_get();
        Hilos::$cluster = null;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        Hilos::$env = $this->previousEnv;
        if ($this->previousProjectZone === false) {
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        } else {
            putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $this->previousProjectZone);
        }
        date_default_timezone_set($this->previousProcessZone);
        parent::tearDown();
    }

    public function testStandaloneStartAndTheTwoReportIntervals(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        $first = Hilos::$sr?->getNextQueuedSignal();
        self::assertSame(HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT, $first?->signalName->getName());
        $payload = $first?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $payload);
        self::assertSame('standalone', $payload->picture->nodeId);
        self::assertSame(NodeRole::Master, $payload->picture->role);

        $now = microtime(true);
        $agent->updatePicture(new NodeDaemonPicture('standalone', NodeRole::Slave, 1));
        $agent->reportIfDue($now + 4.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $agent->reportIfDue($now + 6.0);
        $changed = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $changed);
        self::assertSame(NodeRole::Slave, $changed->picture->role);
        self::assertGreaterThan(1, $changed->picture->sampledAt);

        $agent->reportIfDue($now + 65.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $agent->reportIfDue($now + 67.0);
        self::assertNotNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /** A local measurement changes the next report and survives other section updates. */
    public function testMasterMeasurementsUpdatePictureWithoutLosingOtherSections(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $measurements = new DaemonMeasurements(100, null, null, null, null, null,
            null, 0, 0, null, null, null);
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterMeasurementsSignalData('standalone', $measurements)),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS,
        );
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        self::assertEquals($measurements, $reported->picture->measurements);
        self::assertFalse($reported->picture->sameContent($reported->picture->withMeasurements(null)));
        self::assertEquals($measurements, $reported->picture->sampledAt(12)->withProcesses(null)
            ->withCron(null)->withEnvironment(null)->withStanding(null)->withHttp(null)->measurements);
        $http = new DaemonHttpPicture('127.0.0.1', 8080, 10, [], new HttpRouteTally(0, 0, 0, null));
        $standing = new DaemonNodeStanding(1, 2, false, null);
        $withSections = $reported->picture->withHttp($http)->withStanding($standing);
        self::assertEquals($http, $withSections->withMeasurements(null)->http);
        self::assertEquals($standing, $withSections->withMeasurements(null)->standing);
        self::assertEquals($measurements, $withSections->withProcesses(null)->withCron(null)->measurements);
    }

    /** A frame from another node or an agent cannot replace this node's measurement. */
    public function testForeignMasterMeasurementsAreRefused(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $measurements = new DaemonMeasurements(100, null, null, null, null, null,
            null, 0, 0, null, null, null);
        foreach ([[SignalSource::AGENT, 'standalone'], [SignalSource::DAEMON, 'other-node']] as [$sender, $nodeId]) {
            try {
                $agent->onSignalAgent(
                    new AgentSignalData(data: new DaemonMasterMeasurementsSignalData($nodeId, $measurements)),
                    $sender,
                    HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS,
                );
                self::fail('Foreign master measurements were accepted');
            } catch (AgentException) {
            }
        }
        $agent->reportIfDue(microtime(true) + 6.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testNodeWireRejectsAnInvalidIdentityOrRole(): void
    {
        $good = new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 12));
        self::assertEquals($good, DaemonNodePictureSignalData::fromArray($good->toArray()));

        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray(['nodeId' => 'n1', 'role' => 'leader', 'sampledAt' => 12]);
    }

    public function testMasterFrameReplacesProcessesAndChangesTheNextWholeReport(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $roster = new DaemonProcessRoster([], [], 1);
        $wire = new DaemonMasterProcessRosterSignalData('standalone', $roster);

        $agent->onSignalAgent(
            new AgentSignalData(data: DaemonMasterProcessRosterSignalData::fromArray($wire->toArray())),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
        );
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        self::assertEquals($roster, $reported->picture->processes);
    }

    public function testMasterStandingReplacesTheSectionAndChangesTheNextWholeReport(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $standing = new DaemonNodeStanding(1, 2, false, null);
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterStandingSignalData('standalone', $standing)),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_STANDING,
        );
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        self::assertEquals($standing, $reported->picture->standing);

        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterStandingSignalData('standalone', new DaemonNodeStanding(0, 0, false, null))),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_STANDING,
        );
        $agent->reportIfDue(microtime(true) + 12.0);
        $changed = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $changed);
        self::assertSame(0, $changed->picture->standing?->sessions);
    }

    public function testMasterHttpReplacesTheSectionAndChangesTheNextWholeReport(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $http = new DaemonHttpPicture('127.0.0.1', 8080, 10, [], new HttpRouteTally(0, 0, 0, null));
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterHttpSignalData('standalone', $http)),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_HTTP,
        );
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        self::assertEquals($http, $reported->picture->http);
        self::assertEquals($http, $reported->picture->withProcesses(new DaemonProcessRoster([], null, 0))->http);
        self::assertEquals($http, $reported->picture->withStanding(null)->http);
    }

    public function testForeignMasterHttpIsRefused(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $http = new DaemonHttpPicture('127.0.0.1', 8080, 10, [], new HttpRouteTally(0, 0, 0, null));
        foreach ([[SignalSource::AGENT, 'standalone'], [SignalSource::DAEMON, 'other-node']] as [$sender, $nodeId]) {
            try {
                $agent->onSignalAgent(
                    new AgentSignalData(data: new DaemonMasterHttpSignalData($nodeId, $http)),
                    $sender,
                    HilosSignalConstants::DAEMON_MASTER_HTTP,
                );
                self::fail('Foreign master HTTP frame was accepted');
            } catch (AgentException) {
            }
        }
        $agent->reportIfDue(microtime(true) + 6.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testForeignMasterStandingIsRefused(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        foreach ([[SignalSource::AGENT, 'standalone'], [SignalSource::DAEMON, 'other-node']] as [$sender, $nodeId]) {
            try {
                $agent->onSignalAgent(
                    new AgentSignalData(data: new DaemonMasterStandingSignalData($nodeId, new DaemonNodeStanding(0, 0, false, null))),
                    $sender,
                    HilosSignalConstants::DAEMON_MASTER_STANDING,
                );
                self::fail('Foreign master standing was accepted');
            } catch (AgentException) {
            }
        }
        $agent->reportIfDue(microtime(true) + 6.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testForeignSenderOrNodeIsRefused(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        foreach ([
            [SignalSource::AGENT, 'standalone'],
            [SignalSource::DAEMON, 'other-node'],
        ] as [$sender, $nodeId]) {
            try {
                $agent->onSignalAgent(
                    new AgentSignalData(data: new DaemonMasterProcessRosterSignalData($nodeId, new DaemonProcessRoster([], null, 0))),
                    $sender,
                    HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
                );
                self::fail('Foreign master roster was accepted');
            } catch (AgentException) {
            }
        }
        $agent->reportIfDue(microtime(true) + 6.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testCronWireRequiresEveryFieldAndPreservesUnknownVersusEmpty(): void
    {
        $unknown = new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 12));
        self::assertNull(DaemonNodePictureSignalData::fromArray($unknown->toArray())->picture->cron);

        $known = new DaemonNodePictureSignalData(new NodeDaemonPicture(
            'n1',
            NodeRole::Master,
            12,
            null,
            new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'daily', '0 3 * * *', null, 100000)]),
        ));
        self::assertEquals($known, DaemonNodePictureSignalData::fromArray($known->toArray()));
        $incomplete = $known->toArray();
        unset($incomplete[DaemonNodePictureSignalData::cron]);
        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray($incomplete);
    }

    public function testEnvironmentSummaryIsRequiredOnWireAndPreservedByOtherPictureUpdates(): void
    {
        $fingerprint = new NodeEnvironmentFingerprint('APP_MODE', 'string', EnvSource::PROCESS, false, '0123456789abcdef');
        $summary = new NodeEnvironmentSummary(8, 1, 2, 3, 4, [$fingerprint]);
        $picture = new NodeDaemonPicture('n1', NodeRole::Master, 12, environment: $summary);
        $wire = new DaemonNodePictureSignalData($picture);
        $this->assertSame([
            DaemonNodePictureSignalData::catalogKeys => 8,
            DaemonNodePictureSignalData::missingRequired => 1,
            DaemonNodePictureSignalData::fromExample => 2,
            DaemonNodePictureSignalData::drifted => 3,
            DaemonNodePictureSignalData::orphans => 4,
            DaemonNodePictureSignalData::fingerprints => [[
                DaemonNodePictureSignalData::key => 'APP_MODE',
                DaemonNodePictureSignalData::type => 'string',
                DaemonNodePictureSignalData::source => 'process',
                DaemonNodePictureSignalData::perNode => false,
                DaemonNodePictureSignalData::digest => '0123456789abcdef',
            ]],
        ], $wire->toArray()[DaemonNodePictureSignalData::environment]);
        $this->assertEquals($summary, DaemonNodePictureSignalData::fromArray($wire->toArray())->picture->environment);
        $this->assertEquals($summary, $picture->withProcesses(new DaemonProcessRoster([], null, 0))->environment);
        $this->assertEquals($summary, $picture->withCron(null)->environment);
        $this->assertEquals($summary, $picture->sampledAt(13)->environment);
        $this->assertFalse($picture->sameContent($picture->withEnvironment(null)));

        $incomplete = $wire->toArray();
        unset($incomplete[DaemonNodePictureSignalData::environment]);
        try {
            DaemonNodePictureSignalData::fromArray($incomplete);
            $this->fail('Missing environment summary was accepted');
        } catch (InvalidFormatException) {
        }
        $negative = $wire->toArray();
        $negative[DaemonNodePictureSignalData::environment][DaemonNodePictureSignalData::drifted] = -1;
        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray($negative);
    }

    public function testEnvironmentFingerprintWireRejectsInvalidRows(): void
    {
        $fingerprint = new NodeEnvironmentFingerprint('APP_MODE', 'string', EnvSource::PROCESS, false, '0123456789abcdef');
        $wire = (new DaemonNodePictureSignalData(new NodeDaemonPicture(
            'n1', NodeRole::Master, 12, environment: new NodeEnvironmentSummary(1, 0, 0, 0, 0, [$fingerprint]),
        )))->toArray();

        $invalid = [];
        $missing = $wire;
        unset($missing[DaemonNodePictureSignalData::environment][DaemonNodePictureSignalData::fingerprints]);
        $invalid[] = $missing;
        foreach ([
            [DaemonNodePictureSignalData::type, 'other'],
            [DaemonNodePictureSignalData::source, 'other'],
            [DaemonNodePictureSignalData::digest, null],
            [DaemonNodePictureSignalData::digest, '0123456789abcdeg'],
            [DaemonNodePictureSignalData::perNode, 'false'],
        ] as [$key, $value]) {
            $changed = $wire;
            $changed[DaemonNodePictureSignalData::environment][DaemonNodePictureSignalData::fingerprints][0][$key] = $value;
            $invalid[] = $changed;
        }
        $duplicate = $wire;
        $duplicate[DaemonNodePictureSignalData::environment][DaemonNodePictureSignalData::fingerprints][] =
            $duplicate[DaemonNodePictureSignalData::environment][DaemonNodePictureSignalData::fingerprints][0];
        $invalid[] = $duplicate;

        $refused = 0;
        foreach ($invalid as $payload) {
            try {
                DaemonNodePictureSignalData::fromArray($payload);
                $this->fail('Invalid fingerprint wire row was accepted');
            } catch (InvalidFormatException) {
                $refused++;
            }
        }
        $this->assertSame(count($invalid), $refused);
    }

    public function testCronWireRejectsMissingNullableRowTime(): void
    {
        $wire = (new DaemonNodePictureSignalData(new NodeDaemonPicture(
            'n1',
            NodeRole::Master,
            12,
            null,
            new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'daily', '0 3 * * *', null, null)]),
        )))->toArray();
        unset($wire[DaemonNodePictureSignalData::cron][DaemonNodePictureSignalData::rules][0][DaemonNodePictureSignalData::lastRunAt]);
        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray($wire);
    }

    public function testMasterCronWireRejectsMissingNullableTime(): void
    {
        $wire = (new DaemonMasterCronSignalData('n1', null, [
            new DaemonCronRuleReport('daily', '0 3 * * *', null),
        ]))->toArray();
        unset($wire[DaemonMasterCronSignalData::rules][0][DaemonMasterCronSignalData::lastRunAt]);
        $this->expectException(InvalidFormatException::class);
        DaemonMasterCronSignalData::fromArray($wire);
    }

    public function testCronMillisecondsSurviveReportsPictureClusterAndTable(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $master = new DaemonMasterCronSignalData('standalone', null, [
            new DaemonCronRuleReport('minute', '* * * * *', 1_767_225_540_000),
            new DaemonCronRuleReport('never', 'invalid', null),
        ]);
        $agentReport = new DaemonAgentCronSignalData('a-agent', [
            new DaemonCronRuleReport('minute', '* * * * *', 1_767_225_540_000),
            new DaemonCronRuleReport('never', 'invalid', null),
        ]);
        $agent->onSignalAgent(
            new AgentSignalData(data: DaemonMasterCronSignalData::fromArray(json_decode(json_encode($master->toArray()), true))),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_CRON,
        );
        $agent->onSignalAgent(
            new AgentSignalData(data: DaemonAgentCronSignalData::fromArray(json_decode(json_encode($agentReport->toArray()), true))),
            'agent/a-agent',
            HilosSignalConstants::DAEMON_AGENT_CRON,
        );
        self::assertSame(1_767_225_660, CronRule::nextRunAfter('* * * * *', 1_767_225_600));
        new ReflectionMethod(DaemonNodeAgent::class, 'rebuildCron')->invoke($agent, 1_767_225_600);
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        $node = DaemonNodePictureSignalData::fromArray(json_decode(json_encode($reported->toArray()), true));
        self::assertSame($reported->toArray(), $node->toArray());
        $portion = new DaemonClusterPicturePortionSignalData(true, [new ClusterDaemonNodeView(
            'standalone', true, new ClusterDaemonNodeSlot('standalone', $node->picture, 42),
        )]);
        $cluster = DaemonClusterPicturePortionSignalData::fromArray(json_decode(json_encode($portion->toArray()), true));
        self::assertSame($portion->toArray(), $cluster->toArray());
        self::assertSame(42, $cluster->nodes[0]->slot?->receivedAt);
        ClusterDaemonPictureMirror::forgetPicture();
        try {
            ClusterDaemonPictureMirror::applyPortion($cluster);
            $rows = new HilosDaemonCronTable()->getPage(new TableQueryDTO(filter: ['node' => 'standalone']))->rows;
            self::assertSame(['/minute', '/never', 'a-agent/minute', 'a-agent/never'], array_map(
                static fn ($row): string => $row->rowKey,
                $rows,
            ));
            foreach ([0, 2] as $index) {
                self::assertSame(1_767_225_540_000, $rows[$index]->toArray()['lastRunAt']);
                self::assertSame(1_767_225_660_000, $rows[$index]->toArray()['nextRunAt']);
            }
            foreach ([1, 3] as $index) {
                self::assertNull($rows[$index]->toArray()['lastRunAt']);
                self::assertNull($rows[$index]->toArray()['nextRunAt']);
            }
        } finally {
            ClusterDaemonPictureMirror::forgetPicture();
        }
    }

    public function testCronPictureRejectsUnsortedRows(): void
    {
        $this->expectException(InvalidFormatException::class);
        new DaemonCronPicture(null, [
            new DaemonCronRulePicture('agent-b', 'daily', '* * * * *', null, null),
            new DaemonCronRulePicture(null, 'master', '* * * * *', null, null),
        ]);
    }

    public function testMasterCronCalculatesNextRunAndPreservesProcessRoster(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $roster = new DaemonProcessRoster([], [], 0);
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterProcessRosterSignalData('standalone', $roster)),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
        );
        $master = new DaemonMasterCronSignalData('standalone', null, [
            new DaemonCronRuleReport('daily', '0 3 * * *', 123000),
        ]);
        $agent->onSignalAgent(new AgentSignalData(data: $master), SignalSource::DAEMON, HilosSignalConstants::DAEMON_MASTER_CRON);
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        self::assertEquals($roster, $reported->picture->processes);
        self::assertSame('daily', $reported->picture->cron?->rules[0]->name);
        self::assertSame(123000, $reported->picture->cron?->rules[0]->lastRunAt);
        self::assertGreaterThan(time() * 1000, $reported->picture->cron?->rules[0]->nextRunAt);

        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterCronSignalData('standalone', DaemonCronPicture::IDLE_NOT_LEADER, $master->rules)),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_CRON,
        );
        $agent->reportIfDue(microtime(true) + 12.0);
        $follower = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $follower);
        self::assertSame(DaemonCronPicture::IDLE_NOT_LEADER, $follower->picture->cron?->idleReason);
        self::assertNull($follower->picture->cron?->rules[0]->nextRunAt);
        self::assertEquals($roster, $follower->picture->processes);
    }

    public function testForeignMasterCronIsRefused(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $this->expectException(AgentException::class);
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterCronSignalData('elsewhere', null, [])),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_CRON,
        );
    }

    public function testExpiredNextRunIsRecomputedOnTick(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterCronSignalData('standalone', null, [
                new DaemonCronRuleReport('each-minute', '* * * * *', null),
            ])),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_CRON,
        );
        $pictureProperty = new ReflectionProperty(DaemonNodeAgent::class, 'picture');
        $picture = $pictureProperty->getValue($agent)->withCron(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'each-minute', '* * * * *', null, (time() + 3600) * 1000),
        ]));
        $pictureProperty->setValue($agent, $picture);
        $agent->onTick();
        self::assertSame($picture, $pictureProperty->getValue($agent));
        $pictureProperty->setValue($agent, $picture->withCron(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'each-minute', '* * * * *', null, intdiv(time(), 60) * 60000),
        ])));
        $agent->onTick();
        self::assertGreaterThan(time() * 1000, $pictureProperty->getValue($agent)->cron->rules[0]->nextRunAt);
        self::assertLessThanOrEqual((time() + 60) * 1000, $pictureProperty->getValue($agent)->cron->rules[0]->nextRunAt);
    }

    public function testAgentRowsAreSortedThenPrunedByRosterAndEmptyReport(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterCronSignalData('standalone', null, [
                new DaemonCronRuleReport('master', '0 3 * * *', null),
            ])),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_CRON,
        );
        foreach (['z-agent', 'a-agent'] as $agentId) {
            $agent->onSignalAgent(
                new AgentSignalData(data: new DaemonAgentCronSignalData($agentId, [
                    new DaemonCronRuleReport('daily', '0 3 * * *', 123000),
                ])),
                'agent/' . $agentId,
                HilosSignalConstants::DAEMON_AGENT_CRON,
            );
        }
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        self::assertSame([null, 'a-agent', 'z-agent'], array_map(
            static fn (DaemonCronRulePicture $rule): ?string => $rule->agentId,
            $reported->picture->cron?->rules ?? [],
        ));
        self::assertGreaterThan(time() * 1000, $reported->picture->cron?->rules[1]->nextRunAt);

        $roster = new DaemonProcessRoster([
            new DaemonWorkerPicture(1, 'regular', 101, 4096, [new DaemonAgentPicture('z-agent', 'node', 'node')]),
        ], null, 0);
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterProcessRosterSignalData('standalone', $roster)),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
        );
        $agent->reportIfDue(microtime(true) + 12.0);
        $pruned = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $pruned);
        self::assertSame([null, 'z-agent'], array_map(
            static fn (DaemonCronRulePicture $rule): ?string => $rule->agentId,
            $pruned->picture->cron?->rules ?? [],
        ));

        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonAgentCronSignalData('z-agent', [])),
            'agent/z-agent',
            HilosSignalConstants::DAEMON_AGENT_CRON,
        );
        $agent->reportIfDue(microtime(true) + 18.0);
        $withdrawn = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $withdrawn);
        self::assertSame([null], array_map(
            static fn (DaemonCronRulePicture $rule): ?string => $rule->agentId,
            $withdrawn->picture->cron?->rules ?? [],
        ));
    }

    public function testMasterAndAgentNextRunFollowTheProjectZoneWhileTheProcessIsUtc(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . self::WARSAW);
        date_default_timezone_set(TimeConstants::UTC_TIME_ZONE);

        $before = time();
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonMasterCronSignalData('standalone', null, [
                new DaemonCronRuleReport('daily', self::DAILY_AT_THREE, 123000),
            ])),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_CRON,
        );
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonAgentCronSignalData('a-agent', [
                new DaemonCronRuleReport('daily', self::DAILY_AT_THREE, 456000),
            ])),
            'agent/a-agent',
            HilosSignalConstants::DAEMON_AGENT_CRON,
        );
        $after = time();
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;

        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        $rules = $reported->picture->cron?->rules ?? [];
        self::assertSame([null, 'a-agent'], array_map(
            static fn (DaemonCronRulePicture $rule): ?string => $rule->agentId,
            $rules,
        ));
        self::assertSame([123000, 456000], array_map(
            static fn (DaemonCronRulePicture $rule): ?int => $rule->lastRunAt,
            $rules,
        ));
        foreach ($rules as $rule) {
            $this->assertDailyRunOnTheProjectClock($rule->nextRunAt, $before, $after);
        }
        self::assertSame(TimeConstants::UTC_TIME_ZONE, date_default_timezone_get());
    }

    public function testAgentCronRefusesAnotherSender(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $this->expectException(AgentException::class);
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonAgentCronSignalData('a-agent', [])),
            'agent/other-agent',
            HilosSignalConstants::DAEMON_AGENT_CRON,
        );
    }

    /**
     * @param int|null $nextRunAt Next run in epoch milliseconds
     * @param int $before Unix time read before the node counted the run
     * @param int $after Unix time read after the node counted the run
     */
    private function assertDailyRunOnTheProjectClock(?int $nextRunAt, int $before, int $after): void
    {
        self::assertIsInt($nextRunAt);
        // The picture carries milliseconds; the scheduler still calculates in seconds.
        self::assertGreaterThan($before * 1000, $nextRunAt);
        self::assertLessThanOrEqual(($after + 2 * self::SECONDS_PER_DAY) * 1000, $nextRunAt);

        $moment = new DateTimeImmutable('@' . intdiv($nextRunAt, 1000));
        self::assertSame('03:00', $moment->setTimezone(new DateTimeZone(self::WARSAW))->format(self::CLOCK_FORMAT));
        // 03:00 in Warsaw is 02:00Z in winter and 01:00Z in summer, never 03:00Z of the process zone.
        self::assertContains($moment->format(self::CLOCK_FORMAT), ['01:00', '02:00']);
        self::assertContains(intdiv($nextRunAt, 1000), [
            CronRule::nextRunAfter(self::DAILY_AT_THREE, $before),
            CronRule::nextRunAfter(self::DAILY_AT_THREE, $after),
        ]);
    }
}
