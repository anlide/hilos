<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessGate;

/**
 * PageAccountFrozenException - the person is frozen, and the page is not one of the exits (HIL-945).
 *
 * A freeze takes away using the product and nothing else: a page for signed-in people or for
 * administrators refuses a frozen person unless it declared itself open while frozen
 * ({@see AbstractPage::OPEN_WHILE_FROZEN}). Raised by {@see PageAccessGate} after "who is this"
 * and before "is this an admin", so a frozen administrator hears that they are frozen, not that
 * they lack a right.
 *
 * A 403 like its parent - every place that catches a forbidden page handles it without a change -
 * with a code of its own, because the answer on the screen is a different one: accept the new
 * terms, not sign in and not go away.
 */
final class PageAccountFrozenException extends PageForbiddenException
{
    /** Machine-readable error code carried to the client subscription error. */
    public const string ERROR_CODE = 'account_frozen';

    /**
     * Creates the frozen-account refusal.
     *
     * @param string $message Human-readable error message
     */
    public function __construct(string $message = 'Account frozen')
    {
        parent::__construct($message);
    }
}
