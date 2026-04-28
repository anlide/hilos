<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Settings\DTO\SettingAddActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingDeleteActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingUpdateActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingsTableSnapshotDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosSettingsPage;
use Throwable;

/**
 * SettingsPage - Hilos settings page handler.
 *
 * Handles initial settings table load on subscribe (with catalogKeys for Add modal)
 * and setting add/update/delete actions.
 */
final class SettingsPage extends AbstractHilosSettingsPage
{
    /**
     * Sends the initial settings table full snapshot with catalog keys to the user on page subscription.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param PageRouteParams $params Route params from page subscription (unused)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_HILOS_SETTINGS,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::settings => new SettingsTableSnapshotDTO(
                    Hilos::$table->settings->getFullSnapshot(),
                    array_keys(SettingsCatalog::getCatalog()),
                )],
            ),
        );
    }

    /**
     * Routes incoming setting actions (add/update/delete) to the appropriate handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name (for error reporting)
     * @param ActionPayloadDTO $dto Action payload (SettingAddActionDTO|SettingUpdateActionDTO|SettingDeleteActionDTO)
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException On table mutation or signal failure
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::SETTING_ADD:
                if (!$dto instanceof SettingAddActionDTO) {
                    throw new InvalidActionPayloadException($action, SettingAddActionDTO::class, $dto);
                }
                $this->handleAdd($acceptKey, $dto);

                break;

            case ChatSignalConstants::SETTING_UPDATE:
                if (!$dto instanceof SettingUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, SettingUpdateActionDTO::class, $dto);
                }
                $this->handleUpdate($acceptKey, $dto);

                break;

            case ChatSignalConstants::SETTING_DELETE:
                if (!$dto instanceof SettingDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, SettingDeleteActionDTO::class, $dto);
                }
                $this->handleDelete($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Sends settings table action failures to the initiating client.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Action payload
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->sendToUser(
            ChatSignalConstants::TABLE_ACTION_ERROR,
            $acceptKey,
            new TableActionErrorSignalData(TableChatContext::settings, $action, $e->getMessage()),
        );
    }

    /**
     * Adds a new setting and broadcasts the mutation.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param SettingAddActionDTO $dto Add action payload
     * @throws TableActionException If key is empty or not in catalog
     * @throws HilosException If add or broadcast fails
     */
    private function handleAdd(string $acceptKey, SettingAddActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        $mutation = Hilos::$table->settings->actions->add($dto->key, $dto->value);
        $signal = new TableMutationSignalData(TableChatContext::settings, $mutation);

        $this->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->emitSettingRowChanged($mutation, $acceptKey);
    }

    /**
     * Updates an existing setting and broadcasts the mutation.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param SettingUpdateActionDTO $dto Update action payload
     * @throws TableActionException If key is empty or setting not found
     */
    private function handleUpdate(string $acceptKey, SettingUpdateActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        if (Hilos::$db->settings->findByKey($dto->key) === null) {
            throw new TableActionException("Setting '{$dto->key}' not found");
        }

        $mutation = Hilos::$table->settings[$dto->key]->actions->updateValue($dto->value);
        $signal = new TableMutationSignalData(TableChatContext::settings, $mutation);

        $this->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->emitSettingRowChanged($mutation, $acceptKey);
    }

    /**
     * Deletes a setting and broadcasts the mutation.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param SettingDeleteActionDTO $dto Delete action payload
     * @throws TableActionException If key is empty or setting not found
     */
    private function handleDelete(string $acceptKey, SettingDeleteActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        if (Hilos::$db->settings->findByKey($dto->key) === null) {
            throw new TableActionException("Setting '{$dto->key}' not found");
        }

        $mutation = Hilos::$table->settings[$dto->key]->actions->delete();
        $signal = new TableMutationSignalData(TableChatContext::settings, $mutation);

        $this->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->emitSettingRowChanged($mutation, $acceptKey);
    }

    /**
     * Emits a settings table row change for pending mutation fan-out.
     *
     * @param TableRowMutationDTO $mutation Source table mutation returned by the table action
     * @param string $acceptKey Initiating WebSocket connection key
     */
    private function emitSettingRowChanged(TableRowMutationDTO $mutation, string $acceptKey): void
    {
        $this->emitChangeDb(
            ChatSignalConstants::EMIT_CHAT_SETTING_ROW_CHANGED,
            new EmitDbChangeSignalData(
                sourceEvent: new TableSourceEventDTO(
                    sourceKey: HilosDbContext::settings,
                    sourceRowKey: $mutation->rowKey,
                    mutationType: $mutation->type,
                ),
                excludeAcceptKey: $acceptKey,
                actorUserId: Hilos::$rt->connections[$acceptKey]?->userId,
            ),
        );
    }
}
