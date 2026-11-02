<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use PHPUnit\Framework\TestCase;

/** The mirror distinguishes no frame, a partial frame and an empty full answer. */
final class DaemonPictureMirrorTest extends TestCase
{
    protected function setUp(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        parent::tearDown();
    }

    public function testPartialBeforeFullAndEmptyFullAreDistinct(): void
    {
        self::assertFalse(ClusterDaemonPictureMirror::known());
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(false, [$this->view('n1')]));
        self::assertTrue(ClusterDaemonPictureMirror::known());
        self::assertFalse(ClusterDaemonPictureMirror::hasFullSnapshot());
        self::assertCount(1, ClusterDaemonPictureMirror::picture()?->nodes());

        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, []));
        self::assertTrue(ClusterDaemonPictureMirror::hasFullSnapshot());
        self::assertSame([], ClusterDaemonPictureMirror::picture()?->nodes());
    }

    public function testPortionReplacesOnlyItsNamedNodeAndViewerZeroKeepsThePicture(): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [$this->view('n1'), $this->view('n2')]));
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(false, [
            new ClusterDaemonNodeView('n1', false, null),
        ]));
        self::assertNull(ClusterDaemonPictureMirror::picture()?->node('n1')?->slot);
        self::assertNotNull(ClusterDaemonPictureMirror::picture()?->node('n2')?->slot);
        ClusterDaemonPictureMirror::addViewer('ak');
        ClusterDaemonPictureMirror::addViewer('ak');
        self::assertSame(1, ClusterDaemonPictureMirror::viewerCount());
        ClusterDaemonPictureMirror::removeViewer('ak');
        self::assertTrue(ClusterDaemonPictureMirror::known());
    }

    public function testWireKeepsAnUnknownMemberAndRefusesAHalfKnownSlot(): void
    {
        $frame = new DaemonClusterPicturePortionSignalData(true, [new ClusterDaemonNodeView('n1', true, null)]);
        $restored = DaemonClusterPicturePortionSignalData::fromArray($frame->toArray());
        self::assertTrue($restored->nodes[0]->online);
        self::assertNull($restored->nodes[0]->slot);

        $broken = $frame->toArray();
        $broken[DaemonClusterPicturePortionSignalData::nodes][0][DaemonClusterPicturePortionSignalData::receivedAt] = 10;
        $this->expectException(InvalidFormatException::class);
        DaemonClusterPicturePortionSignalData::fromArray($broken);
    }

    public function testNamesSurviveSnapshotsChangedPortionsAndMissingRosterRows(): void
    {
        $known = $this->view('n1');
        $frame = new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, $known->slot, 'Control'),
            new ClusterDaemonNodeView('n2', false, null, 'Data'),
            new ClusterDaemonNodeView('old', false, null),
        ]);
        self::assertSame(['Control', 'Data', null], array_column($frame->toArray()['nodes'], 'name'));
        $parsed = DaemonClusterPicturePortionSignalData::fromArray($frame->toArray());
        self::assertEquals($frame, $parsed);
        ClusterDaemonPictureMirror::applyPortion($parsed);
        self::assertSame('Control', ClusterDaemonPictureMirror::picture()->node('n1')->name);
        self::assertSame('Data', ClusterDaemonPictureMirror::picture()->node('n2')->name);
        self::assertNull(ClusterDaemonPictureMirror::picture()->node('old')->name);

        $changed = new DaemonClusterPicturePortionSignalData(false, [
            new ClusterDaemonNodeView('n1', true, $known->slot, 'Main control'),
        ]);
        ClusterDaemonPictureMirror::applyPortion(DaemonClusterPicturePortionSignalData::fromArray($changed->toArray()));
        self::assertSame('Main control', ClusterDaemonPictureMirror::picture()->node('n1')->name);
        self::assertSame('Data', ClusterDaemonPictureMirror::picture()->node('n2')->name);
        self::assertEquals($known->slot, ClusterDaemonPictureMirror::picture()->node('n1')->slot);

        $withoutName = $frame->toArray();
        unset($withoutName['nodes'][0]['name']);
        self::assertNull(DaemonClusterPicturePortionSignalData::fromArray($withoutName)->nodes[0]->name);
    }

    public function testWireRefusesANonStringName(): void
    {
        $wire = new DaemonClusterPicturePortionSignalData(true, [$this->view('n1')])->toArray();
        $wire['nodes'][0]['name'] = false;
        $this->expectException(InvalidFormatException::class);
        DaemonClusterPicturePortionSignalData::fromArray($wire);
    }

    public function testWireRefusesAFrameWhoseInnerNodeIdDoesNotMatchTheSlot(): void
    {
        $frame = new DaemonClusterPicturePortionSignalData(true, [$this->view('n1')]);
        $broken = $frame->toArray();
        $broken[DaemonClusterPicturePortionSignalData::nodes][0][DaemonClusterPicturePortionSignalData::picture]['nodeId'] = 'n2';
        $this->expectException(InvalidFormatException::class);
        DaemonClusterPicturePortionSignalData::fromArray($broken);
    }

    public function testWireRefusesANodeMapWhereAListIsRequired(): void
    {
        $this->expectException(InvalidFormatException::class);
        DaemonClusterPicturePortionSignalData::fromArray([
            DaemonClusterPicturePortionSignalData::snapshot => true,
            DaemonClusterPicturePortionSignalData::nodes => ['n1' => []],
        ]);
    }

    private function view(string $nodeId): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView(
            $nodeId,
            true,
            new ClusterDaemonNodeSlot($nodeId, new NodeDaemonPicture($nodeId, NodeRole::Master, 10), 11),
        );
    }
}
