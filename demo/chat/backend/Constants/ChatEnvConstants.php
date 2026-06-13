<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * Chat demo project-specific environment keys.
 */
final class ChatEnvConstants
{
    public const string CHAT_FILES_QUARANTINE_DIR = 'CHAT_FILES_QUARANTINE_DIR';

    public const string CHAT_FILES_PUBLISHED_DIR = 'CHAT_FILES_PUBLISHED_DIR';

    /**
     * Internal nginx location prefix for X-Accel-Redirect file serving. Empty
     * means the daemon streams attachment bytes itself (dev, no web server in
     * front); a non-empty prefix delegates streaming to nginx (test/prod).
     */
    public const string CHAT_FILES_XACCEL_LOCATION = 'CHAT_FILES_XACCEL_LOCATION';
}
