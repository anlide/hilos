<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * ChannelConfigField - one declarative config field a delivery channel exposes (HIL-200).
 *
 * A channel descriptor lists these through {@see AbstractDeliveryChannel::configFields()};
 * the admin channel-config page renders one table row per field and the framework
 * settings-catalog fragment ({@see ChannelSettingsCatalog}) derives one settings key
 * per non-secret field from it. Because the page is built from this list, a new
 * channel (sms 285, push 199) adds its fields here and the admin surface picks them
 * up without any page edit.
 *
 * An operational field (host, port, from-address, timeout) is settings-editable and
 * layered settings-override -> env -> {@see $default} by {@see ChannelConfigResolver}.
 * A {@see $secret} field (SMTP password/username, API token) is env-only: it is never
 * editable and its value is never sent to the browser, only its set/not-set state.
 */
final class ChannelConfigField
{
    /**
     * @param string $key Field key (also the fields-table row key and the settings sub-key)
     * @param string $label Human label shown in the admin table
     * @param string $type Value type (see SettingsCatalogConstants::TYPE_*)
     * @param bool $secret Whether the field is an env-only secret (never editable, value never exposed)
     * @param EnvConstants|string|null $envKey Env variable backing the field's env-source value, or null for none
     * @param bool|float|int|string $default Descriptor default used when neither a settings override nor env apply
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = SettingsCatalogConstants::TYPE_STRING,
        public readonly bool $secret = false,
        public readonly EnvConstants|string|null $envKey = null,
        public readonly bool|float|int|string $default = '',
    ) {
    }
}
