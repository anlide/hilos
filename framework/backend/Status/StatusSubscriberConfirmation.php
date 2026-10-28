<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Ephemeral owner-mail result; never a page payload or an ORM property. */
final readonly class StatusSubscriberConfirmation
{
    /**
     * @param int $subscriberId Persisted subscription id
     * @param string $token Raw confirmation secret for afterCommit mail only
     */
    public function __construct(public int $subscriberId, public string $token)
    {
    }
}
