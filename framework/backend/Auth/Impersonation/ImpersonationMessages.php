<?php

declare(strict_types=1);

namespace Hilos\Auth\Impersonation;

/**
 * The words a takeover is refused with when a setting closes it (HIL-1170).
 *
 * The same sentences stand under a switched-off button on the person's card, where the screen
 * tells the reason before anybody presses; the server speaks them when the setting changed while
 * the card was open, or when the command line asked.
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
}
