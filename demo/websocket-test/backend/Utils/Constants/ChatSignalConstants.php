<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Utils\Constants;

/**
 * ChatSignalConstants - Chat signal name constants
 *
 * Defines signal name constants used in chat demo.
 */
class ChatSignalConstants
{
    public const string START = 'start';
    public const string MESSAGE = 'message';
    public const string FILE = 'file';
    public const string RENAME = 'rename';
}

