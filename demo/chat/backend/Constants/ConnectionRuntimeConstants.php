<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * Runtime connection property keys and UI phase values.
 */
final class ConnectionRuntimeConstants
{
    public const string userState = 'userState';

    /**
     * No visible moderation state.
     */
    public const string OUTBOUND_MODERATION_PHASE_NONE = '';

    /**
     * Moderation phase while an outbound user message is being checked.
     */
    public const string OUTBOUND_MODERATION_PHASE_CHECKING = 'checking';

    /**
     * Moderation phase for a user-retryable rejected message.
     */
    public const string OUTBOUND_MODERATION_PHASE_REJECTED = 'rejected';

    /**
     * Moderation phase for unavailable moderation, or attachments that could not be published.
     */
    public const string OUTBOUND_MODERATION_PHASE_UNAVAILABLE = 'unavailable';

    /**
     * No visible rename moderation state.
     */
    public const string RENAME_MODERATION_PHASE_NONE = '';

    /**
     * Moderation phase while a user-initiated rename is being checked.
     */
    public const string RENAME_MODERATION_PHASE_CHECKING = 'checking';

    /**
     * Moderation phase for a rejected display name.
     */
    public const string RENAME_MODERATION_PHASE_REJECTED = 'rejected';

    /**
     * Moderation phase for unavailable rename moderation.
     */
    public const string RENAME_MODERATION_PHASE_UNAVAILABLE = 'unavailable';
}
