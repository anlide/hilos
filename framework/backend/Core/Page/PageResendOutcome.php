<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Browser\Context\BrowserContext;

/**
 * The outcome of re-sending a whole page to one connection (HIL-1236).
 *
 * A page is re-sent after its delivery failed: the client was told its page could not be
 * delivered and wiped it, so the first delivery that succeeds afterwards owes the page whole.
 * The re-send runs the frame a subscribe runs - the verdict and then the page's own answer -
 * and only Answered pays the debt. A refused or failed probe of an existing debt sends no
 * repeated error or log line ({@see BrowserContext::resendWholePage()}).
 */
enum PageResendOutcome
{
    /** A page_response went out: the page is whole again on the client. */
    case Answered;

    /** The verdict refused the subscription; an existing page debt remains and the probe is silent. */
    case Refused;

    /** Building the answer failed; an existing page debt remains and the probe is silent. */
    case Failed;

    /** Nothing went out: no page in this process serves the subscription, so nobody could answer it. */
    case Unserved;
}
