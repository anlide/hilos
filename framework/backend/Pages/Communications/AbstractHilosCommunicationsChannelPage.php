<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteDoneSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\NotificationDraft;
use Hilos\Pages\Communications\DTO\HilosChannelSettingResetActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelSettingUpdateActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelTestActionDTO;

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
 * Writes go through {@see SettingsLibraryAgent}, the single owner of the settings collection
 * (HIL-946), and the resolver/table reactively redraw the affected rows off the same source-bus
 * announcement as before. The two config writes run in two steps: this page keeps the ADMIN
 * level and everything it can judge without reading a row — the channel, the field, the secret
 * flag, the type and the descriptor's validator — and the library writes. Test send does not
 * move: it writes no settings. It resolves the acting admin's address for the channel and emits
 * a channel-narrowed notification, exercising the real delivery path (HIL-201) rather than a
 * bespoke ping.
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

    /**
     * The library's answer to whichever config write this page forwarded (HIL-946).
     *
     * A name of this page's own, not shared with the general settings screen: the map of
     * page-owned signals holds one entry per name, and two pages under one name would overwrite
     * each other without a word.
     */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE => SettingWriteDoneSignalData::class,
        ],
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
     * @throws DatabaseException When the test-send address lookup fails
     * @throws InvalidArgumentException When a config write cannot be handed to the library
     * @throws EmptyValueException When the test notification draft is empty
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET:
                if (!$dto instanceof HilosChannelSettingUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosChannelSettingUpdateActionDTO::class, $dto);
                }
                $this->handleSet($acceptKey, $dto);

                break;

            case HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET:
                if (!$dto instanceof HilosChannelSettingResetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosChannelSettingResetActionDTO::class, $dto);
                }
                $this->handleReset($acceptKey, $dto);

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
     * Asks the owner of the settings collection for one channel field (or the enablement toggle).
     *
     * Everything judged before the ask stays here, and it is everything that can be judged
     * without reading a settings row: the channel, the field, the secret flag, the type and the
     * descriptor's own validator. What crosses is the pair the library writes idempotently.
     *
     * The sentence is composed here too, and echoed back: it names the channel's label and the
     * field's caption, which the owner of the collection has no business learning.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosChannelSettingUpdateActionDTO $dto Set action payload
     * @throws TableActionException When the channel/field is unknown, secret, or fails validation
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function handleSet(string $acceptKey, HilosChannelSettingUpdateActionDTO $dto): void
    {
        $descriptor = $this->requireChannel($dto->channel);

        if ($dto->field === DeliveryChannelSettings::ENABLED_FIELD) {
            // No success sentence here: the toggle answers for itself by switching on screen.
            $this->write($acceptKey, $descriptor->enabledSettingKey(), (bool) $dto->value, null);

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

        $this->write(
            $acceptKey,
            DeliveryChannelSettings::fieldKey($descriptor->name(), $field->key),
            $value,
            "{$descriptor->label()} setting \"{$field->label}\" saved.",
        );
    }

    /**
     * Asks the owner of the settings collection to put one channel field back to its default.
     *
     * The override row for the field is dropped, which the resolver reads as env/default: a
     * cataloged key with no row of its own is on its catalog default.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosChannelSettingResetActionDTO $dto Reset action payload
     * @throws TableActionException When the channel/field is unknown or secret
     * @throws InvalidArgumentException When the reset frame cannot be named or queued
     */
    private function handleReset(string $acceptKey, HilosChannelSettingResetActionDTO $dto): void
    {
        $descriptor = $this->requireChannel($dto->channel);
        $field = $this->requireField($descriptor, $dto->field);
        if ($field->secret) {
            throw new TableActionException("Field '{$field->key}' is a secret and has no settings override");
        }

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_RESET,
            new SettingResetSignalData(
                replySignal: HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET,
                successMessage: "{$descriptor->label()} setting \"{$field->label}\" is back to its default.",
                key: DeliveryChannelSettings::fieldKey($descriptor->name(), $field->key),
            ),
        );
    }

    /**
     * Asks the owner of the settings collection to put one value under one key.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param string $key Setting key the value stands under
     * @param mixed $value Value to store as the override
     * @param ?string $successMessage Sentence to speak on success, or null for the toggle that has none
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function write(string $acceptKey, string $key, mixed $value, ?string $successMessage): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET,
                successMessage: $successMessage,
                key: $key,
                value: $value,
            ),
        );
    }

    /**
     * Hands one ask to the owner of the settings collection and stops owing the caller an answer.
     *
     * @param string $name Agent-signal name the ask travels under
     * @param SignalDataInterface $ask The ask, carrying whom to answer and what to write
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    private function forward(string $name, SignalDataInterface $ask): void
    {
        $this->agent->sendToAgent($name, $ask);

        if ($this->currentActionRequestId() !== null) {
            $this->deferActionReply();
        }
    }

    /**
     * Answers the administrator whose config write the library has finished (HIL-946).
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not one this page declares
     * @throws LogicException When the payload is not the one its name promises
     * @throws InvalidArgumentException When the ack cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        if ($name !== HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof SettingWriteDoneSignalData) {
            throw new LogicException($name . ' payload must be ' . SettingWriteDoneSignalData::class);
        }

        $this->answerWrite($data->data);
    }

    /**
     * Turns the library's outcome into the ack the administrator's submit is waiting on.
     *
     * Three shapes, as everywhere this form is used: a tracked submit is correlated by its
     * request id and answered on it, and an untracked one has nothing to correlate, so its
     * refusal rides the uncorrelated action-error frame. The sentence is set immediately before
     * the success, because that is the slot the success reads.
     *
     * @param SettingWriteDoneSignalData $done Whom to answer, on which action, and why it was refused
     * @throws InvalidArgumentException When the ack cannot be named
     */
    private function answerWrite(SettingWriteDoneSignalData $done): void
    {
        if ($done->requestId !== null) {
            if ($done->error === null) {
                if ($done->successMessage !== null) {
                    $this->setActionSuccessMessage($done->successMessage);
                }
                $this->sendActionSuccess($done->acceptKey, $done->action, $done->requestId);

                return;
            }

            $this->sendActionFail($done->acceptKey, $done->action, $done->requestId, $done->error);

            return;
        }

        if ($done->error === null) {
            return;
        }

        $this->sendToUser(
            SignalConstants::ACTION_ERROR,
            $done->acceptKey,
            new PageActionErrorSignalData($done->action, $done->error),
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
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
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
        $this->setActionSuccessMessage("Test notification queued to {$address}.");
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
}
