<?php

declare(strict_types=1);

namespace Hilos\Auth\CodeChannel;

use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;

/**
 * CodeChannelRegistry - the code-side catalog of one-time-code delivery channels (HIL-492).
 *
 * Maps a channel name to its {@see CodeChannel} descriptor. The framework ships this
 * empty base (no built-in channels); a project points {@see Hilos::CODE_CHANNEL_REGISTRY}
 * at its own subclass and adds channels by overriding {@see channels()} with
 * `array_replace(parent::channels(), [...])`, so a new channel composes without
 * editing a central list. Modelled on {@see DeliveryChannelRegistry}, which does the
 * same job for notification delivery.
 *
 * It is deliberately NOT that registry reused. A notification channel addresses a
 * recipient by user id and reads their stored preferences; a login code goes to a
 * stranger who has no account yet, addressed by the E.164 number they just typed.
 * Sharing one catalog would mean every channel had to answer both questions, and the
 * first channel that could only do one of them would break the other's contract.
 *
 * Registry ORDER is meaningful and is the project's to set: the surface draws the
 * applicable channels in it, and it decides which channel is promoted when none
 * declares itself primary. An empty registry is a legitimate state - it means the
 * project sends no phone codes at all, and the surface simply offers none.
 */
abstract class CodeChannelRegistry
{
    /**
     * The channel descriptors keyed by channel name, in the order the surface draws them.
     *
     * A project overrides this and merges its own entries onto the parent's:
     *
     * ```php
     * protected static function channels(): array
     * {
     *     return array_replace(parent::channels(), [
     *         SmsCodeChannel::NAME => new SmsCodeChannel(),
     *     ]);
     * }
     * ```
     *
     * @return array<string, CodeChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return [];
    }

    /**
     * Returns every registered channel descriptor keyed by name.
     *
     * @return array<string, CodeChannel> Channel descriptors keyed by name
     */
    public static function all(): array
    {
        return static::channels();
    }

    /**
     * Returns one channel descriptor by name, or null when it is not registered.
     *
     * The lookup the page action guards with: a channel key the browser sends that
     * answers null here is refused before anything is minted, which is what stops a
     * crafted payload from naming a channel the project never enabled.
     *
     * @param string $channel Channel name
     * @return ?CodeChannel Descriptor, or null when unknown
     */
    public static function get(string $channel): ?CodeChannel
    {
        return static::channels()[$channel] ?? null;
    }
}
