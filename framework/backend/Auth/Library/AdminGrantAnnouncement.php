<?php

declare(strict_types=1);

namespace Hilos\Auth\Library;

/**
 * AdminGrantAnnouncement - what one pass of {@see AbstractSessionsLibraryAgent::announceAdminGrant()}
 * managed to tell, in a number and a reason (HIL-849).
 *
 * `sessions` counts the live sessions that received the state frame and `error` names why the
 * pass stopped, null when it ran to the end. The two are not one value: a pass that told
 * nobody is the everyday answer for a person with no tab open, while a pass that told two of
 * five and then failed still carries the two - and a count returned through an exception would
 * carry neither. That is the whole reason the announcement stops throwing and starts returning
 * this: the operator is owed both halves of what happened, and the flag was written either way.
 */
final class AdminGrantAnnouncement
{
    /**
     * @param int $sessions Live sessions that received the state frame
     * @param ?string $error Why the pass stopped, null when it ran to the end
     */
    public function __construct(
        public readonly int $sessions,
        public readonly ?string $error,
    ) {
    }
}
