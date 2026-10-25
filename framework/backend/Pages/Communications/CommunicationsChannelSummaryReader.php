<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigResolver;
use Hilos\Notification\Delivery\ChannelConfigSource;
use Hilos\Pages\Communications\DTO\CommunicationsChannelSummary;

/** Reads one current summary without retaining settings or resolved values. */
final class CommunicationsChannelSummaryReader
{
    /**
     * @param AbstractDeliveryChannel $descriptor Registered channel descriptor
     * @return CommunicationsChannelSummary Metadata and counts for the channel's three pages
     * @throws DatabaseException When persisted settings cannot be read
     * @throws SettingException When settings metadata or the enablement value is invalid
     * @throws EnvException When an env-backed config field is invalid
     * @throws InvalidArgumentException When the resolved object type does not match its collection
     * @throws LogicException When a settings collection is not configured
     */
    public function read(AbstractDeliveryChannel $descriptor): CommunicationsChannelSummary
    {
        $channel = $descriptor->name();
        $resolver = new ChannelConfigResolver();
        $missingFields = 0;
        $fieldCount = 0;
        $secretFieldCount = 0;
        $overriddenFieldCount = 0;
        foreach ($descriptor->configFields() as $field) {
            $source = $resolver->resolve($channel, $field)->source;
            $fieldCount++;
            if ($field->secret) {
                $secretFieldCount++;
            }
            if ($source === ChannelConfigSource::DEFAULT) {
                $missingFields++;
            }
            if ($source === ChannelConfigSource::SETTINGS) {
                $overriddenFieldCount++;
            }
        }

        return new CommunicationsChannelSummary(
            channel: $channel,
            label: $descriptor->label() ?? $channel,
            enabled: Hilos::$setting[$descriptor->enabledSettingKey()]->bool(),
            configured: $missingFields === 0,
            driver: $descriptor->driver(),
            missingFields: $missingFields,
            fieldCount: $fieldCount,
            secretFieldCount: $secretFieldCount,
            overriddenFieldCount: $overriddenFieldCount,
        );
    }
}
