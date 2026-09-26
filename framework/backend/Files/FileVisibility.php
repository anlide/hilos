<?php

declare(strict_types=1);

namespace Hilos\Files;

/**
 * Who may be given a published file of the files registry (HIL-131, HIL-336).
 *
 * The values are the `visibility` ENUM of the hilos_file table word for word. The registry
 * only stores the answer; the download handler (HIL-138) is what reads it. A narrower rule -
 * a group, a role, one object - is not a fourth case but the project's own check behind
 * {@see self::AUTHENTICATED}.
 */
enum FileVisibility: string
{
    /** Given to anybody, a guest without a cookie included: assets of a public page. */
    case PUBLIC = 'public';

    /** Given to any signed-in person: an attachment in a chat everybody signed in reads. */
    case AUTHENTICATED = 'authenticated';

    /** Given only to the person who owns the file: a private gallery. */
    case OWNER = 'owner';
}
