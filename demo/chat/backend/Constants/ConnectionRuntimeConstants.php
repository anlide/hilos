<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * Runtime connection property keys and UI phase values.
 */
final class ConnectionRuntimeConstants
{
    public const string userState = 'userState';
    public const string attachmentDrafts = 'attachmentDrafts';

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
     * Moderation phase for unavailable moderation or missing attachment state.
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

    /**
     * No upload state visible to the frontend.
     */
    public const string FILE_UPLOAD_PHASE_IDLE = '';

    /**
     * Backend accepted metadata and the client may stream binary frames.
     */
    public const string FILE_UPLOAD_PHASE_READY = 'ready';

    /**
     * The client is streaming binary frames for the active upload.
     */
    public const string FILE_UPLOAD_PHASE_UPLOADING = 'uploading';

    /**
     * Upload init or binary streaming failed; retry is allowed.
     */
    public const string FILE_UPLOAD_PHASE_FAILED = 'failed';
}
