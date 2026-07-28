<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Database\Object\Collection\NotificationPreferences as ObjectNotificationPreferences;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Notification\DTO\NotificationChannelPreferenceActionDTO;
use Hilos\Notification\DTO\NotificationChannelState;
use Hilos\Notification\DTO\NotificationPreferencesChangedSignalData;
use Hilos\Notification\DTO\NotificationPreferencesSectionData;
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

    public function testMutedChannelsDegradesToAllowedWhenTableNotActivated(): void
    {
        // A project registers the preference collection unconditionally but may not
        // have activated the hilos_notification_preference table (no migration).
        // The read must degrade to an empty muted set — every channel allowed —
        // rather than query a missing table, keeping the profile subscription and
        // the emit-time dispatch inert until a project activates the table. With no
        // schema loaded (no such table), the activation gate short-circuits before
        // any query.
        Schema::reset();
        $preferences = ObjectNotificationPreferences::initDB(Objects::LAZY_STRATEGY_KEY);

        self::assertSame([], $preferences->mutedChannels(1));
        self::assertTrue($preferences->isAllowed(1, 'email'));
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

    public function testTypeRegistryReportsMandatoryPresence(): void
    {
        // The profile section renders its always-on note only when the project
        // declares a mandatory type; the empty framework base declares none.
        self::assertFalse(NotificationTypeRegistry::hasMandatory());
        self::assertTrue(NotificationPreferenceTestTypeRegistry::hasMandatory());
    }

    public function testSectionDataSerializesChannelsAndMandatoryNote(): void
    {
        $section = new NotificationPreferencesSectionData(
            [
                new NotificationChannelState(channel: 'email', label: 'Email', allowed: true, hasAddress: true),
                new NotificationChannelState(channel: 'sms', label: 'SMS', allowed: false, hasAddress: false),
            ],
            mandatoryNote: true,
        );

        self::assertSame([
            NotificationPreferencesSectionData::channels => [
                [
                    NotificationChannelState::channel => 'email',
                    NotificationChannelState::label => 'Email',
                    NotificationChannelState::allowed => true,
                    NotificationChannelState::hasAddress => true,
                ],
                [
                    NotificationChannelState::channel => 'sms',
                    NotificationChannelState::label => 'SMS',
                    NotificationChannelState::allowed => false,
                    NotificationChannelState::hasAddress => false,
                ],
            ],
            NotificationPreferencesSectionData::mandatoryNote => true,
        ], $section->toArray());
    }

    public function testSectionDataSerializesEmptyChannelList(): void
    {
        $section = new NotificationPreferencesSectionData([], mandatoryNote: false);

        self::assertSame([
            NotificationPreferencesSectionData::channels => [],
            NotificationPreferencesSectionData::mandatoryNote => false,
        ], $section->toArray());
    }

    public function testChannelStateSerializesFrontendConfigOnlyWhenPresent(): void
    {
        // A channel with a browser opt-in (push) carries its public config (the
        // VAPID public key) in the row; a channel without one omits the key
        // entirely, so existing rows stay unchanged on the wire.
        $withConfig = new NotificationChannelState(
            channel: 'push',
            label: 'Push',
            allowed: true,
            hasAddress: false,
            config: ['vapid_public' => 'BPk...'],
        );
        $withoutConfig = new NotificationChannelState(
            channel: 'email',
            label: 'Email',
            allowed: true,
            hasAddress: true,
        );

        self::assertSame([
            NotificationChannelState::channel => 'push',
            NotificationChannelState::label => 'Push',
            NotificationChannelState::allowed => true,
            NotificationChannelState::hasAddress => false,
            NotificationChannelState::config => ['vapid_public' => 'BPk...'],
        ], $withConfig->toArray());
        self::assertArrayNotHasKey(NotificationChannelState::config, $withoutConfig->toArray());
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
