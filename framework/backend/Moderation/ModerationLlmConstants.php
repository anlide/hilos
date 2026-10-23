<?php

declare(strict_types=1);

namespace Hilos\Moderation;

/**
 * Names and model defaults of the two LLM profiles the common moderator checks with (HIL-1625).
 *
 * The text profile judges `TEXT` and `TEXT_WITH_FILES` kinds, the image profile judges `IMAGE` kinds.
 * The defaults are the models the chat demo's own moderator runs today, so a project that takes the
 * common moderator keeps the models it already pulled.
 */
final class ModerationLlmConstants
{
    /** LLM profile key of the text check. */
    public const string PROFILE_TEXT = 'hilos.moderation.text';

    /** LLM profile key of the image check. */
    public const string PROFILE_IMAGE = 'hilos.moderation.image';

    /** Model the text check runs on when neither the environment nor a setting names one. */
    public const string DEFAULT_TEXT_MODEL = 'qwen2.5:0.5b';

    /** Vision model the image check runs on when neither the environment nor a setting names one. */
    public const string DEFAULT_IMAGE_MODEL = 'qwen2.5vl:3b';
}
