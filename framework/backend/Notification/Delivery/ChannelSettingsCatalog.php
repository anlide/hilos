<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;

/**
 * ChannelSettingsCatalog - the framework settings-catalog fragment for delivery channels (HIL-200).
 *
 * Derives one settings entry per channel from the project's channel registry
 * ({@see Hilos::notificationChannelRegistryClass()}): a boolean enablement key
 * ({@see DeliveryChannelSettings::enabledKey()}) plus one key per non-secret config
 * field ({@see AbstractDeliveryChannel::configFields()}). Every default is the empty
 * value for its type - the real default lives in env, and {@see ChannelConfigResolver}
 * only reads a settings value when an admin override is persisted. A project folds
 * this into its own catalog with `array_replace(parent::getCatalog(), ...)`, so a new
 * channel or field is picked up without touching the project catalog.
 *
 * Secret fields get no settings key: a secret is env-only and never written to the DB.
 */
final class ChannelSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Builds the delivery-channel settings entries from the registered channels.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return self::entriesFor(Hilos::notificationChannelRegistryClass()::all());
    }

    /**
     * Builds settings entries for a set of channel descriptors.
     *
     * The enablement key plus one entry per non-secret config field, every default
     * empty for its type. Secret fields are skipped (env-only, never persisted).
     *
     * @param iterable<string, AbstractDeliveryChannel> $channels Channel descriptors keyed by name
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function entriesFor(iterable $channels): array
    {
        $catalog = [];
        foreach ($channels as $channel => $descriptor) {
            $catalog[$descriptor->enabledSettingKey()] = [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => false,
            ];

            foreach ($descriptor->configFields() as $field) {
                if ($field->secret) {
                    continue;
                }

                $catalog[DeliveryChannelSettings::fieldKey($channel, $field->key)] = [
                    SettingsCatalogConstants::CATALOG_ENTRY_TYPE => $field->type,
                    SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::emptyDefaultFor($field->type),
                ];
            }
        }

        return $catalog;
    }

    /**
     * Returns the empty catalog default for a value type.
     *
     * @param string $type Value type (see SettingsCatalogConstants::TYPE_*)
     * @return bool|float|int|string Empty default matching the type
     */
    private static function emptyDefaultFor(string $type): bool|float|int|string
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => 0,
            SettingsCatalogConstants::TYPE_FLOAT => 0.0,
            SettingsCatalogConstants::TYPE_BOOLEAN => false,
            default => '',
        };
    }
}
