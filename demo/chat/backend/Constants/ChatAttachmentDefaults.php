<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * Defaults of the two limits on chat attachments, used when no setting is stored.
 */
final class ChatAttachmentDefaults
{
    /** Largest single attachment - the default of the chat's own setting, read by its upload target. */
    public const int DEFAULT_MAX_FILE_BYTES = 10_485_760;

    /** All files the registry keeps - the chat's default of the framework setting files.max_total_bytes. */
    public const int DEFAULT_MAX_TOTAL_BYTES = 104_857_600;
}
