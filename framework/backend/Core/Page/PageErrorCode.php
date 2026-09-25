<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

/**
 * Machine-readable page-subscription refusals that no page class raised.
 *
 * Guard verdicts carry the codes of their PageSubscriptionException species. This
 * class names refusals reached before a project page instance can answer at all.
 */
final class PageErrorCode
{
    /** No page class registered by the project answers this key. */
    public const string NOT_SERVED = 'not_served';
}
