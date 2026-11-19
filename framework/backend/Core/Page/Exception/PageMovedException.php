<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageSignalRouter;
use Throwable;

/**
 * PageMovedException - The page lives at another address now.
 *
 * Thrown from a page's own check of its address - {@see AbstractPage::resolvePageResource()},
 * {@see AbstractPage::onSubscribeBeforeResponse()} or a typed subscribe hook - with the key and
 * ALL the params of the new address. {@see PageSignalRouter} answers it with a page_moved frame,
 * not with a subscription_page_error. Maps to HTTP 301 and error code 'moved'.
 */
final class PageMovedException extends PageSubscriptionException
{
    /**
     * Creates moved exception.
     *
     * @param string $targetPage Key of the page the address moved to
     * @param array<string, string> $targetParams Every route param of the new address
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        public readonly string $targetPage,
        public readonly array $targetParams,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Page moved', 301, 'moved', $previous);
    }
}
