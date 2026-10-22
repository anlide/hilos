<?php

declare(strict_types=1);

namespace Hilos\Moderation;

/**
 * Framework moderation kind keys (HIL-1623).
 */
final class ModerationKindKey
{
    /** A person's display name. */
    public const string PROFILE_NAME = 'hilos_profile_name';

    /** A person's profile photo; the directory hands it out only while the project takes profile photos. */
    public const string PROFILE_PHOTO = 'hilos_profile_photo';
}
