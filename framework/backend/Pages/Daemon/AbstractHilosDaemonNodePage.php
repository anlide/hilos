<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;
use Hilos\Runtime\State\Item\HilosClusterNode;

/** Shared admission of a Daemon page addressed to one known node. */
abstract class AbstractHilosDaemonNodePage extends AbstractHilosPage
{
    /** @var list<string> Runtime roster read when a node child page is subscribed to */
    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When nodeId is missing or empty
     * @throws PageResourceNotFoundException When the requested node is unknown
     * @throws HilosException When the runtime roster cannot be read
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $address = HilosDaemonNodeSubscribeParams::fromPageRouteParams($params);
        if (Hilos::$rt->hilosClusterNodes[$address->nodeId] === null) {
            throw new PageResourceNotFoundException('Unknown Daemon node: ' . $address->nodeId);
        }

        $this->onDaemonNodeSubscribeBeforeResponse($acceptKey, $address);
    }

    /**
     * Re-checks the node before refreshing the entire page answer.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged route parameters
     * @throws HilosException When the node is missing or the page answer fails
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * Extension seam for the node page leaves that need work before the response.
     *
     * @param string $acceptKey Subscribing connection
     * @param HilosDaemonNodeSubscribeParams $address Validated node address
     * @throws HilosException When the subclass refuses or cannot build its answer
     */
    protected function onDaemonNodeSubscribeBeforeResponse(
        string $acceptKey,
        HilosDaemonNodeSubscribeParams $address,
    ): void {
    }
}
