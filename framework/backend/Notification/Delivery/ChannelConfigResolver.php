<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * ChannelConfigResolver - resolves a channel config field's effective value and source (HIL-200).
 *
 * The runtime read behind the admin channel-config page: for one
 * {@see ChannelConfigField} of a channel it layers an admin settings override on top
 * of the env value on top of the descriptor default, and reports which layer won so
 * the page can show the source exactly as the settings table does. This is the one
 * place the settings/env/default precedence lives, so the page, the channel agents,
 * and any test-send read the same effective config.
 *
 * A secret field is never resolved to a value - the value is null and only its
 * set ({@see ChannelConfigSource::ENV}) / not-set ({@see ChannelConfigSource::DEFAULT})
 * state is reported, so a password or token never reaches the browser. Global
 * enablement is a separate boolean setting ({@see DeliveryChannelSettings::enabledKey()}),
 * not a config field, and is not resolved here.
 */
final class ChannelConfigResolver
{
    /**
     * Resolves the effective value and source for one channel config field.
     *
     * @param string $channel Channel name that owns the field
     * @param ChannelConfigField $field Field descriptor to resolve
     * @return ResolvedChannelConfig Effective value and its source (value null for a secret field)
     * @throws DatabaseException When a persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When the env variable value is invalid for its type
     */
    public function resolve(string $channel, ChannelConfigField $field): ResolvedChannelConfig
    {
        if ($field->secret) {
            $source = $this->envIsSet($field->envKey) ? ChannelConfigSource::ENV : ChannelConfigSource::DEFAULT;

            return new ResolvedChannelConfig($field->key, $source, null);
        }

        $settingKey = DeliveryChannelSettings::fieldKey($channel, $field->key);
        if ($this->hasSettingsOverride($settingKey)) {
            return new ResolvedChannelConfig(
                $field->key,
                ChannelConfigSource::SETTINGS,
                $this->settingValue($settingKey, $field->type),
            );
        }

        if ($field->envKey !== null && Hilos::$env !== null) {
            return new ResolvedChannelConfig(
                $field->key,
                ChannelConfigSource::ENV,
                $this->envValue($field->envKey, $field->type),
            );
        }

        return new ResolvedChannelConfig($field->key, ChannelConfigSource::DEFAULT, $field->default);
    }

    /**
     * Whether a persisted admin settings override exists for a field key.
     *
     * @param string $settingKey Field settings key
     * @return bool True when a persisted settings row overrides the field
     * @throws DatabaseException When the persisted settings lookup fails
     */
    private function hasSettingsOverride(string $settingKey): bool
    {
        if (Hilos::$setting === null || !Hilos::$db instanceof HilosDbContext) {
            return false;
        }

        return Hilos::$db->settings[$settingKey]?->value !== null;
    }

    /**
     * Reads the overriding settings value in the field's type.
     *
     * @param string $settingKey Field settings key
     * @param string $type Field value type (see SettingsCatalogConstants::TYPE_*)
     * @return bool|float|int|string Typed settings value
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     */
    private function settingValue(string $settingKey, string $type): bool|float|int|string
    {
        $setting = Hilos::$setting[$settingKey];

        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => $setting->int(),
            SettingsCatalogConstants::TYPE_FLOAT => $setting->float(),
            SettingsCatalogConstants::TYPE_BOOLEAN => $setting->bool(),
            default => $setting->string(),
        };
    }

    /**
     * Reads the env value backing a field in the field's type.
     *
     * @param EnvConstants|string $envKey Env variable name
     * @param string $type Field value type (see SettingsCatalogConstants::TYPE_*)
     * @return bool|float|int|string Typed env value (or its env-catalog default)
     * @throws EnvException When the env variable value is invalid for its type
     */
    private function envValue(EnvConstants|string $envKey, string $type): bool|float|int|string
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => Hilos::$env->int($envKey),
            SettingsCatalogConstants::TYPE_FLOAT => Hilos::$env->float($envKey),
            SettingsCatalogConstants::TYPE_BOOLEAN => Hilos::$env->bool($envKey),
            default => Hilos::$env->string($envKey),
        };
    }

    /**
     * Whether a secret field's backing env variable is set to a non-empty value.
     *
     * @param EnvConstants|string|null $envKey Secret env variable name, or null for none
     * @return bool True when the env variable resolves to a non-empty string
     * @throws EnvException When the env variable value is invalid for its type
     */
    private function envIsSet(EnvConstants|string|null $envKey): bool
    {
        return $envKey !== null && Hilos::$env !== null && Hilos::$env->string($envKey) !== '';
    }
}
