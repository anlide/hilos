<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatCronConstants - cron job name constants for chat demo.
 */
final class ChatCronConstants
{
    /** @var string Cleanup history cron job name */
    public const string CLEANUP_HISTORY = 'cleanup_history';

    /** @var string Cleanup expired uploaded attachment drafts */
    public const string CLEANUP_ATTACHMENT_DRAFTS = 'cleanup_attachment_drafts';

    /** @var string Free the registration holds whose confirmation code ran out (HIL-415) */
    public const string SWEEP_REGISTRATION_RESERVATIONS = 'sweep_registration_reservations';
}
