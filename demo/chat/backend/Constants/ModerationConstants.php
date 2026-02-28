<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

use Hilos\Constants\LLMConstants;

/**
 * ModerationConstants - Default values for moderation LLM
 *
 * Env var names are in Hilos\Constants\EnvConstants.
 */
class ModerationConstants
{
    /** Default model name */
    public const string DEFAULT_MODEL = LLMConstants::DEFAULT_LOCAL_CHAT_MODEL;

    /** Default base URL */
    public const string DEFAULT_URL = LLMConstants::DEFAULT_LOCAL_URL;

    /** Default timeout seconds */
    public const float DEFAULT_TIMEOUT_SEC = LLMConstants::DEFAULT_TIMEOUT_SEC;
}
