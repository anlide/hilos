<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Typed aggregate without loading subscriber rows into the shared store. */
final readonly class StatusSubscriberCountsData
{
    /**
     * @param int $total All subscriptions
     * @param int $confirmed Confirmed subscriptions
     * @param int $pending Unconfirmed subscriptions
     * @param int $maxSubscriberId Captured upper bound for a recipient pass, zero for an empty table
     */
    public function __construct(public int $total, public int $confirmed, public int $pending, public int $maxSubscriberId)
    {
    }
}
