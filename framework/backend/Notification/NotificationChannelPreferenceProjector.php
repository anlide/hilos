<?php

declare(strict_types=1);

namespace Hilos\Notification;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\NotificationPreferences as ObjectNotificationPreferences;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\DTO\NotificationChannelState;
use Hilos\Notification\DTO\NotificationPreferencesSectionData;

/**
 * NotificationChannelPreferenceProjector - the profile section's channel → allowed view (HIL-485).
 *
 * Projects the channels a recipient can decide about — the same set the delivery
 * dispatcher fans over: a channel appears only when it is globally enabled
 * (HIL-200) and the recipient has a resolvable address for it (HIL-196). Each
 * surviving channel maps to whether the recipient currently allows it, read from
 * the sparse opt-out table (no muted row = allowed). Feeds two contracts with the
 * same full channel → allowed map (never a delta): the profile subscription
 * payload and the {@see NotificationSignalName::PREFERENCES_CHANGED} multi-device
 * sync signal.
 *
 * Mandatory notification types ({@see NotificationTypeRegistry}) are a type-level
 * concern, not a channel toggle, so they never enter this map; the profile renders
 * their always-on note separately.
 */
final class NotificationChannelPreferenceProjector
{
    /**
     * Builds a recipient's channel → allowed map over the channels they can toggle.
     *
     * @param int $userId Recipient user id
     * @return array<string, bool> Channel name → allowed (true) / muted (false), over globally-enabled channels the recipient has an address on
     * @throws DatabaseException When a preference or address lookup query fails
     */
    public function channelPreferenceMap(int $userId): array
    {
        $muted = $this->mutedChannels($userId);

        $map = [];
        foreach (Hilos::notificationChannelRegistryClass()::all() as $name => $descriptor) {
            if (!$this->isGloballyEnabled($descriptor)) {
                continue;
            }
            if ($descriptor->resolveAddress($userId) === null) {
                continue;
            }
            $map[$name] = ObjectNotificationPreferences::channelAllowed($muted, $name);
        }

        return $map;
    }

    /**
     * Builds the profile "Notifications" section for a recipient (HIL-485).
     *
     * Unlike {@see channelPreferenceMap()} (togglable channels only, feeding the
     * multi-device sync signal), the section lists every globally enabled channel —
     * including channels the recipient has no address for, so the frontend can show
     * a disabled row with an "add an address" hint — and carries the mandatory-note
     * flag read from the project's type registry.
     *
     * @param int $userId Recipient user id
     * @return NotificationPreferencesSectionData Section payload for the profile subscription scope
     * @throws DatabaseException When a preference or address lookup query fails
     */
    public function sectionData(int $userId): NotificationPreferencesSectionData
    {
        $muted = $this->mutedChannels($userId);

        $channels = [];
        foreach (Hilos::notificationChannelRegistryClass()::all() as $name => $descriptor) {
            if (!$this->isGloballyEnabled($descriptor)) {
                continue;
            }
            $channels[] = new NotificationChannelState(
                channel: $name,
                label: $descriptor->label(),
                allowed: ObjectNotificationPreferences::channelAllowed($muted, $name),
                hasAddress: $descriptor->resolveAddress($userId) !== null,
            );
        }

        return new NotificationPreferencesSectionData(
            $channels,
            Hilos::notificationTypeRegistryClass()::hasMandatory(),
        );
    }

    /**
     * Reads a recipient's muted channels, or none when the preference table is not activated.
     *
     * A project may register delivery channels without activating the
     * hilos_notification_preference table; the sparse model then treats every
     * channel as allowed, so preferences stay strictly additive.
     *
     * @param int $userId Recipient user id
     * @return list<string> Muted channel names
     * @throws DatabaseException When the preference lookup query fails
     */
    private function mutedChannels(int $userId): array
    {
        $preferences = Hilos::$db?->getObjectCollection(HilosDbContext::notificationPreferences);
        if (!$preferences instanceof ObjectNotificationPreferences) {
            return [];
        }

        return $preferences->mutedChannels($userId);
    }

    /**
     * Whether a channel is globally enabled by its settings switch (HIL-200).
     *
     * A channel whose enablement key is not in the settings catalog is treated as
     * disabled, so the recipient is never shown a toggle for a channel the system
     * does not offer.
     *
     * @param AbstractDeliveryChannel $descriptor Channel descriptor
     * @return bool True when the channel's enablement setting is present and true
     */
    private function isGloballyEnabled(AbstractDeliveryChannel $descriptor): bool
    {
        $key = $descriptor->enabledSettingKey();
        if (Hilos::$setting === null || !isset(Hilos::$setting[$key])) {
            return false;
        }

        return Hilos::$setting[$key]->bool();
    }
}
