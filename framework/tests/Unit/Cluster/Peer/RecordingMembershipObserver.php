<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\MembershipObserver;

/**
 * Membership observer that records the node id of every transition it hears, in order.
 *
 * Stands in for the daemon behind {@see MembershipObserver} in the suites that drive a real peer
 * server, so a case can say exactly which joins and leaves the transport reported.
 */
final class RecordingMembershipObserver implements MembershipObserver
{
    /** @var list<string> Node ids reported joined */
    public array $joined = [];

    /** @var list<string> Node ids reported left */
    public array $left = [];

    /**
     * @param ClusterNode $node Node that joined
     */
    public function onNodeJoined(ClusterNode $node): void
    {
        $this->joined[] = $node->nodeId;
    }

    /**
     * @param ClusterNode $node Node that left
     */
    public function onNodeLeft(ClusterNode $node): void
    {
        $this->left[] = $node->nodeId;
    }
}
