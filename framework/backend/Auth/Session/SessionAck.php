<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;

/**
 * The kinds of ack an auth flow can leave on the sockets of its session (HIL-422).
 *
 * A flow that ends by signing somebody in owes them a sentence before the surface
 * closes — "your account is ready", not a blank screen where a form used to be. The
 * value below is what the server says was achieved; what the person reads is a matter
 * for the view, which holds the copy (HIL-423/424/425 draw it).
 *
 * The registry is a closed set on purpose. The mark travels as one flag on the wire
 * (see {@see HilosSessionConnectionsActions::markAck()}), so the frontend maps a value
 * to a screen — and a value it does not know is a screen nobody wrote. Adding a kind
 * therefore means adding its copy on all three views in the same breath.
 *
 * Signing in with a password is deliberately absent: nothing was achieved beyond
 * arriving, and the surface simply gets out of the way.
 *
 * TODO: the same announcement is owed by three flows that have no leaf yet — a
 * finished restore, a change of password from inside the profile, and a change of
 * email. Each adds a kind here and its copy on the three views.
 */
final class SessionAck
{
    /** A registration whose email confirmation just landed the person inside. */
    public const string REGISTERED = 'auth_registered';

    /** A recovery whose new password was just saved. */
    public const string PASSWORD_CHANGED = 'auth_password_changed';

    /** A magic link that just signed the person in. */
    public const string SIGNED_IN = 'auth_signed_in';
}
