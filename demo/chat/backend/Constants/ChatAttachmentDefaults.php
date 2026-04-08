<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * Default limits and fallbacks for chat file attachments (when DB setting is missing or invalid).
 */
final class ChatAttachmentDefaults
{
    public const int DEFAULT_MAX_FILE_BYTES = 10_485_760;

    public const int DEFAULT_MAX_TOTAL_BYTES = 104_857_600;
}
