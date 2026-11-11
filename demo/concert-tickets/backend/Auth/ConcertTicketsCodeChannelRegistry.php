<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Auth;

use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Auth\CodeChannel\SmsCodeChannel;

/**
 * ConcertTicketsCodeChannelRegistry - the concert-tickets demo's one-time-code channel registry (HIL-492).
 *
 * Registers the one channel a login code may be sent to a phone over: SMS, the channel that
 * reaches a number with no prior relationship. The stand points it at its own gateway, which
 * mails every message on to the stand's inbox.
 */
final class ConcertTicketsCodeChannelRegistry extends CodeChannelRegistry
{
    /**
     * @return array<string, CodeChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [
            SmsCodeChannel::NAME => new SmsCodeChannel(),
        ]);
    }
}
