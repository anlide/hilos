<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRulePicture;
use Hilos\DaemonSection\DaemonCronRuleReport;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonAgentCronSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterCronSignalData;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The node sends a complete frame at start, on change and periodically. */
final class DaemonNodePictureTest extends TestCase
{
    private ?ClusterContext $previousCluster = null;
    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        Hilos::$cluster = null;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
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
            new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'daily', '0 3 * * *', null, 100)]),
        ));
        self::assertEquals($known, DaemonNodePictureSignalData::fromArray($known->toArray()));
        $incomplete = $known->toArray();
        unset($incomplete[DaemonNodePictureSignalData::cron]);
        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray($incomplete);
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
            new DaemonCronRuleReport('daily', '0 3 * * *', 123),
        ]);
        $agent->onSignalAgent(new AgentSignalData(data: $master), SignalSource::DAEMON, HilosSignalConstants::DAEMON_MASTER_CRON);
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        self::assertEquals($roster, $reported->picture->processes);
        self::assertSame('daily', $reported->picture->cron?->rules[0]->name);
        self::assertSame(123, $reported->picture->cron?->rules[0]->lastRunAt);
        self::assertGreaterThan(time(), $reported->picture->cron?->rules[0]->nextRunAt);

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
        $picture = $pictureProperty->getValue($agent);
        $pictureProperty->setValue($agent, $picture->withCron(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'each-minute', '* * * * *', null, time() - 1),
        ])));
        $agent->onTick();
        self::assertGreaterThan(time(), $pictureProperty->getValue($agent)->cron->rules[0]->nextRunAt);
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
                    new DaemonCronRuleReport('daily', '0 3 * * *', 123),
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
        self::assertGreaterThan(time(), $reported->picture->cron?->rules[1]->nextRunAt);

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
}
