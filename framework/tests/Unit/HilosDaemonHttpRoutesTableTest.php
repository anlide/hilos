<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouteTally;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonHttpPicture;
use Hilos\DaemonSection\DaemonHttpRoutePicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTableRow;
use PHPUnit\Framework\TestCase;

/** The HTTP routes table projects one node's ordered route tallies from its picture. */
final class HilosDaemonHttpRoutesTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ClusterDaemonPictureMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        parent::tearDown();
    }

    public function testMissingPictureFilterNodeSlotOrHttpYieldsNoRows(): void
    {
        self::assertSame([], $this->rows('n1'));
        $this->picture($this->node('n1', $this->http([])));
        self::assertSame([], new HilosDaemonHttpRoutesTable()->getPage(new TableQueryDTO())->rows);
        foreach ([null, '', 10, []] as $node) {
            self::assertSame([], new HilosDaemonHttpRoutesTable()->getPage(new TableQueryDTO(
                filter: [HilosDaemonHttpRoutesTable::FILTER_NODE => $node],
            ))->rows);
        }
        self::assertSame([], $this->rows('missing'));
        $this->picture(new ClusterDaemonNodeView('n1', true, null));
        self::assertSame([], $this->rows('n1'));
        $this->picture($this->node('n1', null));
        self::assertSame([], $this->rows('n1'));
    }

    public function testRoutesKeepPictureOrderAndAllFieldsWithUnroutedLast(): void
    {
        $this->picture($this->node('n1', $this->http([
            $this->route('GET', '/a', null, new HttpRouteTally(0, 0, 0, null)),
            $this->route('POST', '/hook', 'webhook', new HttpRouteTally(7, 2, 1, 1200)),
        ], new HttpRouteTally(3, 1, 0, 45))));

        $rows = $this->rows('n1');
        self::assertSame(['GET/%2Fa', 'POST/%2Fhook', 'unrouted'], array_map(static fn ($row): string => $row->rowKey, $rows));
        self::assertSame([
            HilosDaemonHttpRoutesTableRow::rowKey => 'GET/%2Fa',
            HilosDaemonHttpRoutesTableRow::method => 'GET',
            HilosDaemonHttpRoutesTableRow::path => '/a',
            HilosDaemonHttpRoutesTableRow::agentType => null,
            HilosDaemonHttpRoutesTableRow::requests => 0,
            HilosDaemonHttpRoutesTableRow::serverErrors => 0,
            HilosDaemonHttpRoutesTableRow::slowestMs => null,
        ], $rows[0]->toArray());
        self::assertSame('webhook', $rows[1]->agentType);
        self::assertSame([7, 2, 1200], [$rows[1]->requests, $rows[1]->serverErrors, $rows[1]->slowestMs]);
        self::assertSame([null, null, null, 3, 1, 45], [
            $rows[2]->method, $rows[2]->path, $rows[2]->agentType,
            $rows[2]->requests, $rows[2]->serverErrors, $rows[2]->slowestMs,
        ]);
        self::assertSame(array_keys($rows[0]->toArray()), array_keys(new HilosDaemonHttpRoutesTable()->wireFields()));
        self::assertNull(new HilosDaemonHttpRoutesTable()->defaultSort());
        self::assertSame(25, new HilosDaemonHttpRoutesTable()->windowSize());
    }

    public function testSilentNodeKeepsRowsAndKeysDoNotCollide(): void
    {
        $this->picture($this->node('n1', $this->http([
            $this->route('GET', '/a%2Fb'),
            $this->route('GET', '/a/b'),
            $this->route('POST', '/a/b'),
        ]), online: false));

        $rows = $this->rows('n1');
        self::assertSame(['GET/%2Fa%252Fb', 'GET/%2Fa%2Fb', 'POST/%2Fa%2Fb', 'unrouted'],
            array_map(static fn ($row): string => $row->rowKey, $rows));
        $query = new TableQueryDTO(filter: [HilosDaemonHttpRoutesTable::FILTER_NODE => 'n1']);
        self::assertTrue(new HilosDaemonHttpRoutesTable()->containsRow('GET/%2Fa%2Fb', $query));
        self::assertTrue(new HilosDaemonHttpRoutesTable()->containsRow('unrouted', $query));
        self::assertFalse(new HilosDaemonHttpRoutesTable()->containsRow('GET/%2Fa', $query));
    }

    /** @return list<HilosDaemonHttpRoutesTableRow> Rows in the selected node's first window */
    private function rows(string $node): array
    {
        /** @var list<HilosDaemonHttpRoutesTableRow> $rows */
        $rows = new HilosDaemonHttpRoutesTable()->getPage(new TableQueryDTO(
            filter: [HilosDaemonHttpRoutesTable::FILTER_NODE => $node],
        ))->rows;

        return $rows;
    }

    /** @param ClusterDaemonNodeView ...$nodes Node views in a whole picture */
    private function picture(ClusterDaemonNodeView ...$nodes): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $nodes));
    }

    /**
     * @param string $id Node id
     * @param ?DaemonHttpPicture $http HTTP section, if received
     * @param bool $online Whether the node still reports
     * @return ClusterDaemonNodeView Node view with its latest slot
     */
    private function node(string $id, ?DaemonHttpPicture $http, bool $online = true): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView(
            $id,
            $online,
            new ClusterDaemonNodeSlot($id, new NodeDaemonPicture($id, NodeRole::Master, 10, http: $http), 10),
        );
    }

    /**
     * @param list<DaemonHttpRoutePicture> $routes Registered routes in picture order
     * @param ?HttpRouteTally $unrouted Requests that reached no route
     * @return DaemonHttpPicture Node's HTTP section
     */
    private function http(array $routes, ?HttpRouteTally $unrouted = null): DaemonHttpPicture
    {
        return new DaemonHttpPicture('127.0.0.1', 8080, 10, $routes, $unrouted ?? new HttpRouteTally(0, 0, 0, null));
    }

    /**
     * @param string $method Registered method
     * @param string $path Registered path template
     * @param ?string $agentType Agent answering the route
     * @param ?HttpRouteTally $tally Counts in the current window
     * @return DaemonHttpRoutePicture One registered route
     */
    private function route(
        string $method,
        string $path,
        ?string $agentType = null,
        ?HttpRouteTally $tally = null,
    ): DaemonHttpRoutePicture {
        return new DaemonHttpRoutePicture($method, $path, $agentType, $tally ?? new HttpRouteTally(0, 0, 0, null));
    }
}
