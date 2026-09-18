<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Tls\ClusterTlsConfig;

/**
 * The TLS files a peer server built in a unit test is given - and never opens.
 *
 * A peer server takes its node's certificate and trust file at construction (HIL-1034), but the
 * suites that build one neither accept a TLS connection on it nor dial from it: links are placed
 * by hand over bare socket pairs. Nothing reads these paths, so they name nothing on disk, and a
 * suite that did reach for them would fail loudly rather than pass on a real certificate.
 */
final class PeerTestTls
{
    /**
     * @return ClusterTlsConfig Paths no file stands behind
     */
    public static function unread(): ClusterTlsConfig
    {
        return new ClusterTlsConfig('/nonexistent/hilos-unit-test-node.pem', '/nonexistent/hilos-unit-test-ca.pem');
    }
}
