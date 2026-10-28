<?php

declare(strict_types=1);

namespace Hilos\Status;

/** One owner's private best-effort cursor; neither secrets nor ORM wrappers survive a portion. */
final class StatusSubscriberMailTask
{
    public ?int $afterId = null;

    /**
     * @param StatusSubscriberNotice $notice Detached committed event
     * @param int $maxSubscriberId Audience upper bound captured once after commit
     * @param StatusSubscriptionLink $links Validated external links captured at enqueue
     * @param string $projectName Sender's public project name captured at enqueue
     */
    public function __construct(
        public readonly StatusSubscriberNotice $notice,
        public readonly int $maxSubscriberId,
        public readonly StatusSubscriptionLink $links,
        public readonly string $projectName,
    ) {
    }
}
