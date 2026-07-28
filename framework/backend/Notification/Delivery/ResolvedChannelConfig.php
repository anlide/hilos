<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

/**
 * ResolvedChannelConfig - a channel config field's effective value and source (HIL-200).
 *
 * The output of {@see ChannelConfigResolver::resolve()} for one
 * {@see ChannelConfigField}: the field key, where the effective value came from
 * ({@see ChannelConfigSource}), and the value itself. The value is always null for a
 * secret field - a secret is never sent to the browser; its {@see $source} carries
 * the set ({@see ChannelConfigSource::ENV}) / not-set ({@see ChannelConfigSource::DEFAULT})
 * state instead.
 */
final class ResolvedChannelConfig
{
    /**
     * @param string $field Field key this resolution is for
     * @param ChannelConfigSource $source Where the effective value came from
     * @param bool|float|int|string|null $value Effective value, or null for a secret field
     */
    public function __construct(
        public readonly string $field,
        public readonly ChannelConfigSource $source,
        public readonly bool|float|int|string|null $value,
    ) {
    }
}
