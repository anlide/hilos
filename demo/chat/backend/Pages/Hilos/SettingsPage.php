<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Settings\DTO\SettingAddActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingsTableResultDTO;
use Demo\Chat\Tables\Settings\DTO\SettingDeleteActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingUpdateActionDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\HilosException;

/**
 * SettingsPage - Hilos settings page handler.
 *
 * Handles initial settings table load on subscribe (with catalogKeys for Add modal)
 * and setting add/update/delete actions.
 */
final class SettingsPage extends AbstractChatPage
{
    public const string PAGE = HilosPageConstants::HILOS_SETTINGS;

    /**
     * Sends initial settings table data with catalog keys to the user on page subscription.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param array<string, string> $params Route params from page subscription (unused)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_HILOS_SETTINGS,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::settings => new SettingsTableResultDTO(
                    Hilos::$table->settings->get(),
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
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
            switch ($action) {
                case ChatSignalConstants::SETTING_ADD:
                    if ($dto instanceof SettingAddActionDTO) {
                        $this->handleAdd($acceptKey, $dto);
                    }

                    break;

                case ChatSignalConstants::SETTING_UPDATE:
                    if ($dto instanceof SettingUpdateActionDTO) {
                        $this->handleUpdate($acceptKey, $dto);
                    }

                    break;

                case ChatSignalConstants::SETTING_DELETE:
                    if ($dto instanceof SettingDeleteActionDTO) {
                        $this->handleDelete($acceptKey, $dto);
                    }

                    break;

                default:
                    throw new TableActionException("Unknown action: {$action}");
            }
        } catch (TableActionException | InvalidArgumentException $e) {
            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::TABLE_ACTION_ERROR,
                $acceptKey,
                new TableActionErrorSignalData(TableChatContext::settings, $action, $e->getMessage()),
            );
        }
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

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
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

        $mutation = Hilos::$table->settings[$dto->key]->actions->update(['value' => $dto->value]);
        $signal = new TableMutationSignalData(TableChatContext::settings, $mutation);

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
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

        $this->getChatAgent()->sendToUser(ChatSignalConstants::TABLE_MUTATION, $acceptKey, $signal);
        $this->getChatAgent()->sendToAllUsers(ChatSignalConstants::TABLE_MUTATION, $signal, $acceptKey);
    }
}
