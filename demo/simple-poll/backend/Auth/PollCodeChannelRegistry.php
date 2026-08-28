<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Auth;

use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Auth\CodeChannel\SmsCodeChannel;
use Hilos\Auth\CodeChannel\TelegramCodeChannel;

/**
 * PollCodeChannelRegistry - the simple-poll demo's one-time-code channel registry (HIL-492).
 *
 * Registers the channels a login code may be sent to a phone over, in the order the auth
 * surface draws them: SMS first, since it is the channel that reaches a number with no
 * prior relationship and is therefore the primary button, and Telegram beside it for the
 * numbers whose owner put them there.
 *
 * Registry order is the demo's statement about its own surface, not a framework rule - a
 * project that puts a messenger first gets a messenger-first surface with no code change
 * anywhere else.
 *
 * Telegram is registered whether or not a Gateway token is configured, which is the point
 * of the channel answering its own reachability: an unconfigured Gateway reports every
 * number unreachable, so the surface offers the icon and the person falls back to SMS
 * rather than the demo needing two builds.
 */
final class PollCodeChannelRegistry extends CodeChannelRegistry
{
    /**
     * @return array<string, CodeChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [
            SmsCodeChannel::NAME => new SmsCodeChannel(),
            TelegramCodeChannel::NAME => new TelegramCodeChannel(),
        ]);
    }
}
