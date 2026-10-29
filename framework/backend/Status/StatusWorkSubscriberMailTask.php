<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Private best-effort cursor and handoff evidence; never retains recipients or secrets. */
final class StatusWorkSubscriberMailTask
{
    public ?int $afterId = null;
    public int $handedOff = 0;
    public bool $handoffFailed = false;

    /**
     * @param StatusWorkSubscriberNotice $notice Detached committed event
     * @param int $maxSubscriberId Audience ceiling captured once after commit
     * @param StatusSubscriptionLink $links Validated public links
     * @param string $projectName Sender's public project name at enqueue
     * @param StatusWorkMailContext $context Latest committed version's provenance shared by this public identity's pending tasks
     */
    public function __construct(
        public readonly StatusWorkSubscriberNotice $notice,
        public readonly int $maxSubscriberId,
        public readonly StatusSubscriptionLink $links,
        public readonly string $projectName,
        public readonly StatusWorkMailContext $context,
    ) {
    }
}
