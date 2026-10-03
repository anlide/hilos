<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Socket\Server\AbstractServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/** A departing master holds its peer links until queued frames are written (HIL-1207). */
final class PeerServerShutdownReadinessTest extends TestCase
{
    public function testPeerServerWaitsForQueuedOutputOnItsLinks(): void
    {
        $server = new ReflectionClass(PeerServer::class)->newInstanceWithoutConstructor();
        $link = new ReflectionClass(PeerLink::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(AbstractServer::class, 'clients')->setValue($server, [$link]);

        $this->assertTrue($server->isReadyToShutdown());

        $link->sendEncodedFrame('{}');
        $this->assertFalse($server->isReadyToShutdown());

        new ReflectionProperty(AbstractServer::class, 'clients')->setValue($server, []);
        $this->assertTrue($server->isReadyToShutdown());
    }
}
