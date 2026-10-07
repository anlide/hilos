<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use PHPUnit\Framework\TestCase;

/** Strict process roster contract shared by the master and the node's whole picture. */
final class DaemonMasterProcessRosterSignalDataTest extends TestCase
{
    public function testWholeRosterSurvivesMasterNodeAndClusterWire(): void
    {
        $roster = new DaemonProcessRoster([
            new DaemonWorkerPicture(1, 'regular', 101, 8192, [
                new DaemonAgentPicture('a:1', 'node', 'node'),
                new DaemonAgentPicture('b', 'cluster', 'leader'),
            ]),
            new DaemonWorkerPicture(2, 'monopolistic', null, null, [
                new DaemonAgentPicture('c', 'cluster', 'policy'),
            ]),
        ], ['unplaced:1'], 2);

        $master = new DaemonMasterProcessRosterSignalData('n1', $roster);
        self::assertEquals($master, DaemonMasterProcessRosterSignalData::fromArray($master->toArray()));

        $picture = new NodeDaemonPicture('n1', NodeRole::Master, 10, $roster);
        $nodeWire = new DaemonNodePictureSignalData($picture);
        self::assertEquals($picture, DaemonNodePictureSignalData::fromArray($nodeWire->toArray())->picture);
        self::assertTrue($picture->sameContent($picture->sampledAt(20)));
        self::assertFalse($picture->sameContent(new NodeDaemonPicture('n1', NodeRole::Master, 10)));

        $clusterWire = new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $picture, 11)),
        ]);
        self::assertEquals($clusterWire, DaemonClusterPicturePortionSignalData::fromArray($clusterWire->toArray()));
        self::assertSame($nodeWire->toArray(), $clusterWire->toArray()['nodes'][0]['picture']);
    }

    public function testNullAndKnownEmptyRemainDifferent(): void
    {
        $unknown = new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Slave, 10));
        self::assertArrayHasKey('processes', $unknown->toArray());
        self::assertNull(DaemonNodePictureSignalData::fromArray($unknown->toArray())->picture->processes);

        $knownEmpty = new DaemonNodePictureSignalData(new NodeDaemonPicture(
            'n1',
            NodeRole::Slave,
            10,
            new DaemonProcessRoster([], null, 0),
        ));
        self::assertSame([], $knownEmpty->toArray()['processes']['workers']);
        self::assertEquals($knownEmpty, DaemonNodePictureSignalData::fromArray($knownEmpty->toArray()));
        self::assertSame([], (new DaemonProcessRoster([], [], 0))->unplacedAgentIds);
    }

    public function testMissingAndMistypedRequiredFieldsAreRefused(): void
    {
        $good = (new DaemonMasterProcessRosterSignalData('n1', new DaemonProcessRoster([
            new DaemonWorkerPicture(1, 'regular', null, null, []),
        ], null, 0)))->toArray();
        $bad = [];
        $missingWorkers = $good;
        unset($missingWorkers['workers']);
        $bad[] = $missingWorkers;
        $missingUnplaced = $good;
        unset($missingUnplaced['unplacedAgentIds']);
        $bad[] = $missingUnplaced;
        $missingPid = $good;
        unset($missingPid['workers'][0]['pid']);
        $bad[] = $missingPid;
        $wrongPid = $good;
        $wrongPid['workers'][0]['pid'] = '101';
        $bad[] = $wrongPid;
        $wrongNode = $good;
        $wrongNode['nodeId'] = '';
        $bad[] = $wrongNode;
        foreach ($bad as $payload) {
            try {
                DaemonMasterProcessRosterSignalData::fromArray($payload);
                self::fail('Malformed roster was accepted');
            } catch (InvalidFormatException) {
            }
        }

        $node = (new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 10)))->toArray();
        unset($node['processes']);
        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray($node);
    }

    public function testDuplicateAgentAndUnsortedWorkerAreRefused(): void
    {
        $agent = new DaemonAgentPicture('a', 'node', 'node');
        $one = new DaemonWorkerPicture(1, 'regular', 101, 0, [$agent]);
        $two = new DaemonWorkerPicture(2, 'regular', 102, 0, [$agent]);
        try {
            new DaemonProcessRoster([$one, $two], [], 0);
            self::fail('Duplicate agent was accepted');
        } catch (InvalidFormatException) {
        }

        $this->expectException(InvalidFormatException::class);
        new DaemonProcessRoster([$two, $one], [], 0);
    }
}
