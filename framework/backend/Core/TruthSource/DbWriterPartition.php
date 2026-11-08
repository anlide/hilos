<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

/** Closed proofs understood by the DB topology judge. */
enum DbWriterPartition: string
{
    case NotificationChannelShard = 'notification-channel-shard';
}
