<?php

declare(strict_types=1);

namespace Hilos\Pages\Users\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\Users\AbstractHilosUserPage;

/**
 * HilosUserPageSubscribeParams - Typed subscribe params for Hilos user detail page.
 *
 * Parsed from the raw route params by {@see AbstractHilosUserPage::onSubscribe()}
 * before dispatching to subclasses. Guarantees a positive integer `userId`
 * from {@see HilosPageRouteParams::HILOS_USER_USER_ID}.
 */
final class HilosUserPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /**
     * @param int $userId User primary key, always `> 0`
     */
    public function __construct(public readonly int $userId)
    {
    }

    /**
     * Reads the `userId` route param as a positive integer.
     *
     * @param PageRouteParams $params Raw subscribe params
     * @throws MissingPageRouteParamException When `userId` is absent
     * @throws InvalidPageRouteParamException When `userId` is non-numeric or `<= 0`
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(
            userId: $params->requirePositiveInt(HilosPageRouteParams::HILOS_USER_USER_ID),
        );
    }
}
