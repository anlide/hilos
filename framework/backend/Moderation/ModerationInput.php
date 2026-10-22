<?php

declare(strict_types=1);

namespace Hilos\Moderation;

/**
 * What a moderation kind hands the moderator to look at (HIL-1623).
 */
enum ModerationInput: string
{
    /** A piece of text alone, such as a display name. */
    case TEXT = 'text';

    /** One image alone, such as a profile photo. */
    case IMAGE = 'image';

    /** A piece of text together with what is known about the files attached to it. */
    case TEXT_WITH_FILES = 'text_with_files';
}
