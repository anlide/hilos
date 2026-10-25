<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/**
 * Address shared by the channel, configuration and deliveries pages.
 *
 * The wire's channelId names a code-registry entry, not a persisted entity.
 * Registry admission belongs to the page after this route-shape check.
 */
final class HilosChannelPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /**
     * @param string $channelId Channel registry key
     */
    public function __construct(public readonly string $channelId)
    {
    }

    /**
     * @param PageRouteParams $params Raw subscription route parameters
     * @return static Channel address without a registry lookup
     * @throws MissingPageRouteParamException When channelId is missing, empty or not a string
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static($params->requireString(HilosPageRouteParams::HILOS_COMMUNICATIONS_CHANNEL_ID));
    }
}
