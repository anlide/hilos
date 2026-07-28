<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

/**
 * ChannelConfigSource - where a channel config field's effective value came from (HIL-200).
 *
 * The admin channel-config page shows this per field, exactly as the settings table
 * shows its value source: an admin {@see SETTINGS} override wins over the {@see ENV}
 * value (an env variable or its env-catalog default), and {@see DEFAULT} is the
 * descriptor's last-resort value for a field with no env backing. For a secret field
 * the value is never exposed; {@see ENV} then reads as "set in env" and
 * {@see DEFAULT} as "not set".
 */
enum ChannelConfigSource: string
{
    /** An admin settings override is in effect. */
    case SETTINGS = 'settings';

    /** The value comes from an environment variable (or its env-catalog default). */
    case ENV = 'env';

    /** The value is the descriptor's own default (no settings override, no env backing). */
    case DEFAULT = 'default';
}
