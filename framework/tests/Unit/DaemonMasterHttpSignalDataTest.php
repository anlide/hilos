<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouteTally;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\DaemonHttpPicture;
use Hilos\DaemonSection\DaemonHttpRoutePicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterHttpSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use PHPUnit\Framework\TestCase;

/** The HTTP section has one strict shape across master, node, and cluster frames. */
final class DaemonMasterHttpSignalDataTest extends TestCase
{
    public function testMasterNodeAndClusterRoundTripWithUnknownAndKnownEmpty(): void
    {
        $http = self::picture();
        $master = new DaemonMasterHttpSignalData('n1', $http);
        self::assertEquals($master, DaemonMasterHttpSignalData::fromArray($master->toArray()));

        $unknown = new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 10));
        self::assertArrayHasKey(DaemonNodePictureSignalData::http, $unknown->toArray());
        self::assertNull($unknown->toArray()[DaemonNodePictureSignalData::http]);
        self::assertNull(DaemonNodePictureSignalData::fromArray($unknown->toArray())->picture->http);

        $node = new NodeDaemonPicture('n1', NodeRole::Master, 10, http: $http);
        $nodeWire = new DaemonNodePictureSignalData($node);
        self::assertEquals($node, DaemonNodePictureSignalData::fromArray($nodeWire->toArray())->picture);
        self::assertSame(
            0,
            $nodeWire->toArray()[DaemonNodePictureSignalData::http][DaemonMasterHttpSignalData::unrouted][DaemonMasterHttpSignalData::requests],
        );

        $cluster = new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $node, 11)),
        ]);
        self::assertEquals($cluster, DaemonClusterPicturePortionSignalData::fromArray($cluster->toArray()));
        self::assertSame($nodeWire->toArray(), $cluster->toArray()['nodes'][0]['picture']);
    }

    public function testMissingAndMalformedMasterFieldsAreRefused(): void
    {
        $good = (new DaemonMasterHttpSignalData('n1', self::picture()))->toArray();
        $bad = [];
        foreach (['nodeId', 'host', 'port', 'countingSince', 'routes', 'unrouted'] as $key) {
            $missing = $good;
            unset($missing[$key]);
            $bad[] = $missing;
        }
        $wrong = $good;
        $wrong['nodeId'] = '';
        $bad[] = $wrong;
        $wrong = $good;
        $wrong['port'] = 65536;
        $bad[] = $wrong;
        $wrong = $good;
        $wrong['routes'][0]['method'] = '';
        $bad[] = $wrong;
        $wrong = $good;
        $wrong['routes'][0]['path'] = '';
        $bad[] = $wrong;
        $wrong = $good;
        $wrong['routes'][0]['agentType'] = '';
        $bad[] = $wrong;
        $wrong = $good;
        unset($wrong['routes'][0]['slowestMs']);
        $bad[] = $wrong;
        $wrong = $good;
        $wrong['routes'][0]['serverErrors'] = 2;
        $bad[] = $wrong;
        $wrong = $good;
        $wrong['routes'][0]['slow'] = 2;
        $bad[] = $wrong;
        $wrong = $good;
        $wrong['routes'][0]['slowestMs'] = null;
        $bad[] = $wrong;
        $wrong = $good;
        $wrong['unrouted']['slowestMs'] = 0;
        $bad[] = $wrong;
        $refused = 0;
        foreach ($bad as $payload) {
            try {
                DaemonMasterHttpSignalData::fromArray($payload);
                self::fail('Malformed HTTP frame was accepted');
            } catch (InvalidFormatException) {
                $refused++;
            }
        }
        self::assertSame(count($bad), $refused);
    }

    public function testUnsortedOrDuplicateRoutesAndMissingNodeSectionAreRefused(): void
    {
        $good = (new DaemonMasterHttpSignalData('n1', self::picture()))->toArray();
        $later = $good['routes'][0];
        $later['path'] = '/z';
        foreach ([[$later, $good['routes'][0]], [$good['routes'][0], $good['routes'][0]]] as $routes) {
            $bad = $good;
            $bad['routes'] = $routes;
            try {
                DaemonMasterHttpSignalData::fromArray($bad);
                self::fail('Unsorted or duplicate HTTP route was accepted');
            } catch (InvalidFormatException) {
            }
        }

        $node = (new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 10)))->toArray();
        unset($node[DaemonNodePictureSignalData::http]);
        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray($node);
    }

    /** @return DaemonHttpPicture Valid HTTP section with one counted route */
    private static function picture(): DaemonHttpPicture
    {
        return new DaemonHttpPicture('127.0.0.1', 8080, 10, [
            new DaemonHttpRoutePicture('GET', '/status', null, new HttpRouteTally(1, 1, 0, 4)),
        ], new HttpRouteTally(0, 0, 0, null));
    }
}
