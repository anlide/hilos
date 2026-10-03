<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Browser\Context\BrowserContext;

/**
 * What re-sending a whole page to one connection put on the wire (HIL-1236).
 *
 * A page is re-sent after its delivery failed: the client was told its page could not be
 * delivered and wiped it, so the first delivery that succeeds afterwards owes the page whole.
 * The re-send runs the frame a subscribe runs - the verdict and then the page's own answer -
 * and the four outcomes differ in what reached the connection, which is all the caller needs
 * to decide whether the connection still has to be told its page failed
 * ({@see BrowserContext::resendWholePage()}).
 */
enum PageResendOutcome
{
    /** A page_response went out: the page is whole again on the client. */
    case Answered;

    /**
     * A refusal went out - the subscription error the verdict reached, or the frame that says
     * no page is served under the name: the subscription now stands where a refused one stands.
     */
    case Refused;

    /** The internal-error frame went out: building the answer failed, and the connection was told so. */
    case Failed;

    /** Nothing went out: no page in this process serves the subscription, so nobody could answer it. */
    case Unserved;
}
