<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Database\Object\Collection\NotificationPreferences as ObjectNotificationPreferences;
use Hilos\Notification\DTO\NotificationChannelPreferenceActionDTO;
use Hilos\Notification\DTO\NotificationPreferencesChangedSignalData;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Notification\NotificationSignalName;
use Hilos\Notification\NotificationTypeDescriptor;
use Hilos\Notification\NotificationTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DB-free per-user channel preference contract (HIL-485).
 *
 * Locks the pure pieces: the sparse-resolve decision (no muted row = allowed,
 * a muted row cuts, mandatory bypasses per the type registry), the action and
 * signal payload round-trips (including the FIELD_DATA envelope unwrap and the
 * channel → allowed map), and the action / signal name constants. The DB-backed
 * upsert/delete and the emit-time dispatch are exercised at integration / e2e
 * (HIL-202).
 */
final class NotificationPreferenceTest extends TestCase
{
    public function testSparseResolveAllowsChannelWithNoMutedRow(): void
    {
        self::assertTrue(ObjectNotificationPreferences::channelAllowed([], 'email'));
        self::assertTrue(ObjectNotificationPreferences::channelAllowed(['telegram'], 'email'));
    }

    public function testSparseResolveCutsAMutedChannel(): void
    {
        self::assertFalse(ObjectNotificationPreferences::channelAllowed(['email'], 'email'));
        self::assertFalse(ObjectNotificationPreferences::channelAllowed(['email', 'telegram'], 'telegram'));
    }

    public function testMandatoryTypeBypassesPreferences(): void
    {
        // The dispatcher only consults per-user preferences for a non-mandatory
        // type; a mandatory type registered in code short-circuits that check.
        self::assertTrue(NotificationPreferenceTestTypeRegistry::isMandatory('security.alert'));
        self::assertFalse(NotificationPreferenceTestTypeRegistry::isMandatory('chat.mention'));
        self::assertFalse(NotificationPreferenceTestTypeRegistry::isMandatory('unregistered.type'));
    }

    public function testChannelPreferenceActionDtoRoundTrips(): void
    {
        $dto = new NotificationChannelPreferenceActionDTO(channel: 'email', enabled: false);
        $restored = NotificationChannelPreferenceActionDTO::fromArray($dto->toArray());

        self::assertSame('email', $restored->channel);
        self::assertFalse($restored->enabled);
        self::assertSame(NotificationPreferenceAction::CHANNEL_SET, $restored->getAction());
        self::assertTrue($restored->isValid());
    }

    public function testChannelPreferenceActionDtoUnwrapsFieldDataEnvelope(): void
    {
        $dto = NotificationChannelPreferenceActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [
                NotificationChannelPreferenceActionDTO::channel => 'telegram',
                NotificationChannelPreferenceActionDTO::enabled => true,
            ],
        ]);

        self::assertSame('telegram', $dto->channel);
        self::assertTrue($dto->enabled);
    }

    public function testChannelPreferenceActionDtoDefaultsToMutedAndInvalidWhenChannelMissing(): void
    {
        $dto = NotificationChannelPreferenceActionDTO::fromArray([]);

        self::assertSame('', $dto->channel);
        self::assertFalse($dto->enabled);
        self::assertFalse($dto->isValid());
    }

    public function testPreferencesChangedSignalDataRoundTrips(): void
    {
        $data = new NotificationPreferencesChangedSignalData(channels: [
            'email' => true,
            'telegram' => false,
        ]);
        $restored = NotificationPreferencesChangedSignalData::fromArray($data->toArray());

        self::assertSame(['email' => true, 'telegram' => false], $restored->channels);
    }

    public function testPreferencesChangedSignalDataCoercesMapValues(): void
    {
        $data = NotificationPreferencesChangedSignalData::fromArray([
            NotificationPreferencesChangedSignalData::channels => [
                'email' => 1,
                'telegram' => 0,
            ],
        ]);

        self::assertSame(['email' => true, 'telegram' => false], $data->channels);
    }

    public function testPreferencesChangedSignalDataDefaultsToEmptyMap(): void
    {
        $data = NotificationPreferencesChangedSignalData::fromArray([]);

        self::assertSame([], $data->channels);
    }

    public function testActionAndSignalNameConstants(): void
    {
        self::assertSame('profile_notification_channel_set', NotificationPreferenceAction::CHANNEL_SET);
        self::assertSame('notification_preferences_changed', NotificationSignalName::PREFERENCES_CHANGED);
    }
}

/**
 * Test fixture: a project-style type registry that declares one mandatory type.
 */
final class NotificationPreferenceTestTypeRegistry extends NotificationTypeRegistry
{
    /**
     * @return array<string, NotificationTypeDescriptor> Type descriptors keyed by type
     */
    protected static function types(): array
    {
        return array_replace(parent::types(), [
            'security.alert' => new NotificationTypeDescriptor(mandatory: true),
            'chat.mention' => new NotificationTypeDescriptor(mandatory: false),
        ]);
    }
}
