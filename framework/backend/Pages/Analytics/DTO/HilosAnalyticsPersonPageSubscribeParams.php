<?php

declare(strict_types=1);

namespace Hilos\Pages\Analytics\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** Numeric person address; whether the person or their history exists is checked by the page. */
final class HilosAnalyticsPersonPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /** @param int $userId Requested account number */
    public function __construct(public readonly int $userId)
    {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Parsed positive account number
     * @throws MissingPageRouteParamException When the account number is absent or empty
     * @throws InvalidPageRouteParamException When the account number is not a positive integer
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(
            userId: $params->requirePositiveInt(HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID),
        );
    }
}
