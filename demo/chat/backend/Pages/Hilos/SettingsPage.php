<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ChatTableContext;
use Demo\Chat\Tables\Settings\DTO\SettingAddActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingDeleteActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingUpdateActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosSettingsPage;
use Throwable;

/**
 * Handles Hilos settings table actions for the chat demo.
 */
final class SettingsPage extends AbstractHilosSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;

    public const array ACTIONS = [
        ChatSignalConstants::SETTING_ADD => SettingAddActionDTO::class,
        ChatSignalConstants::SETTING_UPDATE => SettingUpdateActionDTO::class,
        ChatSignalConstants::SETTING_DELETE => SettingDeleteActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_HILOS_SETTINGS,
    ];

    /**
     * Routes setting add, update, and delete actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException When a routed settings table mutation fails
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
            new TableActionErrorSignalData(ChatTableContext::settings, $action, $e->getMessage()),
        );
    }

    /**
     * Adds a setting through the table action.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param SettingAddActionDTO $dto Add action payload
     * @throws TableActionException When setting key is empty
     * @throws HilosException When catalog validation or setting persistence fails
     */
    private function handleAdd(string $acceptKey, SettingAddActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        Hilos::$table->settings->actions->add($dto->key, $dto->value);
    }

    /**
     * Updates a setting through the table action.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param SettingUpdateActionDTO $dto Update action payload
     * @throws TableActionException When setting key is empty or the setting is missing
     * @throws DatabaseException When settings persistence or row reload fails
     * @throws SettingException When catalog default metadata cannot rebuild the row
     */
    private function handleUpdate(string $acceptKey, SettingUpdateActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        if (!isset(Hilos::$db->settings[$dto->key])) {
            throw new TableActionException("Setting '{$dto->key}' not found");
        }

        Hilos::$table->settings[$dto->key]->actions->updateValue($dto->value);
    }

    /**
     * Deletes a setting through the table action.
     *
     * @param string $acceptKey Requesting WebSocket accept key, kept for handler symmetry
     * @param SettingDeleteActionDTO $dto Delete action payload
     * @throws TableActionException When setting key is empty, missing, or still declared in the catalog
     * @throws DatabaseException When settings persistence fails
     */
    private function handleDelete(string $acceptKey, SettingDeleteActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        if (!isset(Hilos::$db->settings[$dto->key])) {
            throw new TableActionException("Setting '{$dto->key}' not found");
        }

        Hilos::$table->settings[$dto->key]->actions->delete();
    }
}
