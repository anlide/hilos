<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Peer\PeerMarkers;

/**
 * The markers every node of a unit-test mesh carries - one database, one name (HIL-1206).
 *
 * A peer server takes its node's markers at construction, and every hello and welcome carries
 * them; the suites that build servers and feed handshakes by hand are not about the markers, so
 * each side names the same database and every handshake passes the check. The suite that is about
 * them builds its own.
 */
final class PeerTestMarkers
{
    /** @var string Database marker every node of the test mesh reads */
    public const string DATABASE_MARKER = '00000000000000000000000000000001';

    /** @var string Where the test node reads it from, as a refusal would print it */
    public const string DATABASE_PLACE = "database 'hilos_unit' on 127.0.0.1:3306";

    /**
     * @return PeerMarkers Markers of a node on the one shared test database
     */
    public static function shared(): PeerMarkers
    {
        return new PeerMarkers(
            [PeerMarkers::DATABASE => self::DATABASE_MARKER],
            [PeerMarkers::DATABASE => self::DATABASE_PLACE],
        );
    }

    /**
     * @return array<string, string> The markers a handshake from another node of the same mesh carries
     */
    public static function onWire(): array
    {
        return [PeerMarkers::DATABASE => self::DATABASE_MARKER];
    }
}
