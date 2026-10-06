<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** Typed address of one Daemon node; the page checks whether it exists. */
final class HilosDaemonNodeSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /**
     * @param string $nodeId Requested node ID
     */
    public function __construct(public readonly string $nodeId)
    {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Parsed non-empty node address
     * @throws MissingPageRouteParamException When nodeId is missing or empty
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static($params->requireString(HilosPageRouteParams::HILOS_DAEMON_NODE_ID));
    }
}
