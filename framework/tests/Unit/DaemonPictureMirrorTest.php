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
