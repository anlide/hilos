<?php

declare(strict_types=1);

namespace Hilos\Auth\Impersonation;

/**
 * The words a takeover is refused with when a setting closes it (HIL-1170), and the toast that ends one (HIL-1308).
 *
 * The same sentences stand under a switched-off button on the person's card, where the screen
 * tells the reason before anybody presses; the server speaks them when the setting changed while
 * the card was open, or when the command line asked.
 *
 * Impersonation switched off also ends every takeover under way: the session holder returns
 * each to its administrator and explains why on that session's toast, in the words below.
 */
final class ImpersonationMessages
{
    /** Refusal: impersonation is switched off for the whole product. */
    public const string SWITCHED_OFF = 'Impersonation is switched off';

    /** Refusal: the person is blocked and taking a blocked person over is switched off. */
    public const string BLOCKED_OFF = 'Impersonating a blocked person is switched off';

    /** Refusal: the person is frozen and taking a frozen person over is switched off. */
    public const string FROZEN_OFF = 'Impersonating a frozen person is switched off';

    /** Refusal: the person is an administrator and taking another administrator over is switched off. */
    public const string EQUAL_OFF = 'Impersonating another administrator is switched off';

    /** Toast: the takeover the administrator was in ended because impersonation was switched off. */
    public const string ENDED_SWITCHED_OFF = 'Impersonation was switched off, so you are back in your own account.';

    /** Who speaks on the toast, drawn above its sentence. */
    public const string TOAST_SOURCE = 'Impersonation';

    /** Where a click on the toast takes the administrator: the section the switch stands in. */
    public const string TOAST_DESTINATION = '/hilos/security/impersonation';
}
