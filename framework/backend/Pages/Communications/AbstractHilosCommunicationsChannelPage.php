<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\NotificationDraft;
use Hilos\Pages\Communications\DTO\HilosChannelSettingResetActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelSettingUpdateActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelTestActionDTO;
use Hilos\Tables\Settings\HilosSettingsTable;
use LogicException;

/**
 * AbstractHilosCommunicationsChannelPage - single delivery-channel configuration (HIL-200).
 *
 * Owns the channel-config action lifecycle for the whole communications surface: set a
 * field override, reset a field to env/default, and send a test notification. The hub
 * page ({@see AbstractHilosCommunicationsPage}) declares no actions of its own — its
 * enablement toggle rides the shared set action with the `enabled` field
 * ({@see DeliveryChannelSettings::ENABLED_FIELD}) — because a WebSocket action name is
 * globally unique to one owning page; a single owner routes both surfaces and both are
 * served by the same admin agent, so the redraw fans over the tables regardless of
 * which tab triggered the write.
 *
 * Writes go through the framework settings table's actions (the same override store the
 * settings page uses), and the resolver/table reactively redraw the affected rows. Test
 * send resolves the acting admin's address for the channel and emits a channel-narrowed
 * notification, exercising the real delivery path (HIL-201) rather than a bespoke ping.
 *
 * The page is an admin surface: the ADMIN access level inherited from
 * AbstractHilosPage closes its subscription and every action, replacing the
 * former flagless AUTHENTICATED guard and AUTH_ACTIONS list with the stricter
 * inherited default. Projects add a concrete subclass with a
 * `SUBSCRIPTION_AGENT_TYPE`; they add no action code of their own.
 */
abstract class AbstractHilosCommunicationsChannelPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET => HilosChannelSettingUpdateActionDTO::class,
        HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET => HilosChannelSettingResetActionDTO::class,
        HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST => HilosChannelTestActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL,
    ];

    /** Machine type of the self-addressed test notification. */
    private const string TEST_NOTIFICATION_TYPE = 'notifications.channel_test';

    /**
     * Routes channel set, reset, and test actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the target channel/field is unknown, secret, or invalid
     * @throws DatabaseException When a settings write or address lookup fails
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws HilosException When settings catalog validation or persistence fails
     * @throws EmptyValueException When the test notification draft is empty
     * @throws LogicException When the notifications collection is unavailable during a test send
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET:
                if (!$dto instanceof HilosChannelSettingUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosChannelSettingUpdateActionDTO::class, $dto);
                }
                $this->handleSet($dto);

                break;

            case HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET:
                if (!$dto instanceof HilosChannelSettingResetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosChannelSettingResetActionDTO::class, $dto);
                }
                $this->handleReset($dto);

                break;

            case HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST:
                if (!$dto instanceof HilosChannelTestActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosChannelTestActionDTO::class, $dto);
                }
                $this->handleTest($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Writes one channel field's settings override (or the global enablement toggle).
     *
     * The `enabled` pseudo-field writes the channel's boolean enablement key; any other
     * field is validated by type and its descriptor validator before the override is
     * persisted. A secret field is never writable.
     *
     * @param HilosChannelSettingUpdateActionDTO $dto Set action payload
     * @throws TableActionException When the channel/field is unknown, secret, or fails validation
     * @throws DatabaseException When the settings write fails
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws HilosException When settings catalog validation or persistence fails
     */
    private function handleSet(HilosChannelSettingUpdateActionDTO $dto): void
    {
        $descriptor = $this->requireChannel($dto->channel);

        if ($dto->field === DeliveryChannelSettings::ENABLED_FIELD) {
            $this->settingsTable()->actions->add($descriptor->enabledSettingKey(), (bool) $dto->value);

            return;
        }

        $field = $this->requireField($descriptor, $dto->field);
        if ($field->secret) {
            throw new TableActionException("Field '{$field->key}' is a secret and cannot be edited");
        }

        $value = $this->coerceValue($dto->value, $field->type);
        if ($field->validator !== null) {
            $error = ($field->validator)($value);
            if ($error !== null) {
                throw new TableActionException($error);
            }
        }

        $this->settingsTable()->actions->add(
            DeliveryChannelSettings::fieldKey($descriptor->name(), $field->key),
            $value,
        );
    }

    /**
     * Resets one channel field to its env/default value by clearing the override.
     *
     * A cataloged settings key cannot be row-deleted, so the override is nulled out; a
     * null value means "no override, inherit", which the resolver reads as env/default.
     *
     * @param HilosChannelSettingResetActionDTO $dto Reset action payload
     * @throws TableActionException When the channel/field is unknown or secret
     * @throws DatabaseException When the settings write fails
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws HilosException When settings catalog validation or persistence fails
     */
    private function handleReset(HilosChannelSettingResetActionDTO $dto): void
    {
        $descriptor = $this->requireChannel($dto->channel);
        $field = $this->requireField($descriptor, $dto->field);
        if ($field->secret) {
            throw new TableActionException("Field '{$field->key}' is a secret and has no settings override");
        }

        $this->settingsTable()->actions->add(
            DeliveryChannelSettings::fieldKey($descriptor->name(), $field->key),
            null,
        );
    }

    /**
     * Sends a test notification narrowed to the channel, addressed to the acting admin.
     *
     * @param string $acceptKey Accept key of the requesting connection
     * @param HilosChannelTestActionDTO $dto Test action payload
     * @throws TableActionException When the channel is unknown, the caller is unresolved, or has no address
     * @throws DatabaseException When the recipient address lookup fails
     * @throws EmptyValueException When the notification draft is empty
     * @throws LogicException When the notifications collection is unavailable
     */
    private function handleTest(string $acceptKey, HilosChannelTestActionDTO $dto): void
    {
        $descriptor = $this->requireChannel($dto->channel);

        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            throw new TableActionException('No authenticated user to send a test notification to');
        }

        $address = $descriptor->resolveAddress($userId);
        if ($address === null) {
            throw new TableActionException('No address for this channel');
        }

        $notify = Hilos::$notify;
        if ($notify === null) {
            throw new TableActionException('Notifications are not available');
        }

        $notify->emit(new NotificationDraft(
            userId: $userId,
            type: self::TEST_NOTIFICATION_TYPE,
            title: 'Test notification: ' . $descriptor->label(),
            channels: [$descriptor->name()],
        ));
    }

    /**
     * Resolves a registered channel descriptor by name.
     *
     * @param string $channel Channel name
     * @return AbstractDeliveryChannel Channel descriptor
     * @throws TableActionException When the channel is not registered
     */
    private function requireChannel(string $channel): AbstractDeliveryChannel
    {
        $descriptor = Hilos::notificationChannelRegistryClass()::get($channel);
        if ($descriptor === null) {
            throw new TableActionException("Unknown channel: {$channel}");
        }

        return $descriptor;
    }

    /**
     * Resolves a channel's config field descriptor by key.
     *
     * @param AbstractDeliveryChannel $descriptor Owning channel descriptor
     * @param string $field Field key
     * @return ChannelConfigField Field descriptor
     * @throws TableActionException When the field is not declared by the channel
     */
    private function requireField(AbstractDeliveryChannel $descriptor, string $field): ChannelConfigField
    {
        foreach ($descriptor->configFields() as $candidate) {
            if ($candidate->key === $field) {
                return $candidate;
            }
        }

        throw new TableActionException("Unknown field: {$field}");
    }

    /**
     * Coerces a raw action value to its field type for validation and persistence.
     *
     * @param mixed $value Raw payload value
     * @param string $type Field value type (see SettingsCatalogConstants::TYPE_*)
     * @return bool|float|int|string Coerced value
     * @throws TableActionException When a numeric field is given a non-numeric value
     */
    private function coerceValue(mixed $value, string $type): bool|float|int|string
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => is_numeric($value)
                ? (int) $value
                : throw new TableActionException('Value must be a number'),
            SettingsCatalogConstants::TYPE_FLOAT => is_numeric($value)
                ? (float) $value
                : throw new TableActionException('Value must be a number'),
            SettingsCatalogConstants::TYPE_BOOLEAN => (bool) $value,
            default => is_scalar($value)
                ? (string) $value
                : throw new TableActionException('Value must be text'),
        };
    }

    /**
     * Resolves the framework settings table from the table context.
     *
     * @return HilosSettingsTable Registered settings table definition
     * @throws TableActionException When the settings table is not registered in the table context
     */
    private function settingsTable(): HilosSettingsTable
    {
        $table = Hilos::$table?->get(HilosSettingsTable::TABLE);
        if (!$table instanceof HilosSettingsTable) {
            throw new TableActionException('Settings table is not registered in the table context');
        }

        return $table;
    }
}
