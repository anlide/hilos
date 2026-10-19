<?php

declare(strict_types=1);

namespace Hilos\Core\Action;

/**
 * Person-facing sentences for the framework's action refusals.
 */
final class ActionMessages
{
    public const string RATE_LIMITED = 'Too many attempts. Please wait a moment and try again.';

    public const string VIEW_MODE = 'View mode: you can look around, but not change anything.';

    public const string IMPERSONATION_VIEW_ONLY = "View only: nothing can be changed in someone else's account.";

    public const string IMPERSONATED = "This is not available while you work in someone else's account";
}
