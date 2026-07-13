<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Peer\PeerSeed;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for parsing the comma-separated seed list (HIL-178).
 */
final class PeerSeedTest extends TestCase
{
    public function testParsesHostPortEntries(): void
    {
        $seeds = PeerSeed::parseList('10.0.0.1:8095, node-b:9000');

        $this->assertCount(2, $seeds);
        $this->assertSame('10.0.0.1', $seeds[0]->host);
        $this->assertSame(8095, $seeds[0]->port);
        $this->assertSame('node-b', $seeds[1]->host);
        $this->assertSame(9000, $seeds[1]->port);
    }

    public function testSkipsBlankAndMalformedEntries(): void
    {
        $seeds = PeerSeed::parseList(' , a:1 , nohost , c:0 , d:-3 , e:5 ');

        $this->assertCount(2, $seeds);
        $this->assertSame('a', $seeds[0]->host);
        $this->assertSame(1, $seeds[0]->port);
        $this->assertSame('e', $seeds[1]->host);
        $this->assertSame(5, $seeds[1]->port);
    }

    public function testEmptyStringYieldsNoSeeds(): void
    {
        $this->assertSame([], PeerSeed::parseList(''));
    }
}
