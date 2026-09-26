<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion;

/**
 * Refusal texts of account deletion that no neighbouring flow already words (HIL-302).
 *
 * The rest are borrowed as they are: an expired confirmation and an impersonated session
 * speak the step-up's words, a wrong code and the send ceiling speak the codes' words.
 */
final class AccountDeletionMessages
{
    /** A deletion is already scheduled - another tab started it between opening and now. */
    public const string ALREADY_SCHEDULED = 'Your account is already scheduled for deletion';
}
