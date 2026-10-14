<?php

declare(strict_types=1);

namespace Hilos\Pages\Analytics\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** Numeric session address; existence is checked by the page. */
final class HilosAnalyticsBrowserSessionPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /** @param int $browserSessionId Requested analytics browser-session number */
    public function __construct(public readonly int $browserSessionId)
    {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Parsed positive session number
     * @throws MissingPageRouteParamException When the session number is absent or empty
     * @throws InvalidPageRouteParamException When the session number is not a positive integer
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(
            browserSessionId: $params->requirePositiveInt(HilosPageRouteParams::HILOS_ANALYTICS_BROWSER_SESSION_ID),
        );
    }
}
