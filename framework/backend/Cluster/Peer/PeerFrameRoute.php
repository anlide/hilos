<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use WeakReference;

/**
 * @internal Process-local continuation of waiting peer frames. Forward links retain no socket owner;
 * callbacks can outlive the donor without keeping a closed transport alive.
 */
final class PeerFrameRoute
{
    /** Next generation, set before transferring any callback */
    public ?self $successor = null;
    /** Only answers queued in this generation are discarded on replacement */
    public bool $dropsAnswers = false;
    /** @var WeakReference<PeerLink> Endpoint of this generation */
    private readonly WeakReference $endpoint;

    /** @param PeerLink $endpoint Socket owner, borrowed weakly */
    public function __construct(PeerLink $endpoint)
    {
        $this->endpoint = WeakReference::create($endpoint);
    }

    /** @return ?PeerLink Latest endpoint, without depending on any donor's lifetime */
    public function current(): ?PeerLink
    {
        $route = $this;
        while ($route->successor !== null) {
            $route = $route->successor;
        }

        return $route->endpoint->get();
    }
}
