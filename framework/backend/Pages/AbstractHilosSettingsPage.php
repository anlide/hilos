<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Settings\DTO\HilosSettingAddActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingDeleteActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingUpdateActionDTO;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Base class for the framework Hilos settings page.
 *
 * Owns the settings subscribe signal and the add/update/delete action lifecycle
 * over the framework settings table. A project activates the feature by
 * extending this page with a `SUBSCRIPTION_AGENT_TYPE` and registering it; the
 * catalog binds to the settings facade (Hilos::$setting), not to the page.
 */
abstract class AbstractHilosSettingsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SETTINGS;

    public const array ACTIONS = [
        HilosSignalConstants::SETTING_ADD => HilosSettingAddActionDTO::class,
        HilosSignalConstants::SETTING_UPDATE => HilosSettingUpdateActionDTO::class,
        HilosSignalConstants::SETTING_DELETE => HilosSettingDeleteActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SETTINGS,
    ];

    /**
     * Routes setting add, update, and delete actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws HilosException When a routed settings table mutation fails
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::SETTING_ADD:
                if (!$dto instanceof HilosSettingAddActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosSettingAddActionDTO::class, $dto);
                }
                $this->handleAdd($dto);

                break;

            case HilosSignalConstants::SETTING_UPDATE:
                if (!$dto instanceof HilosSettingUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosSettingUpdateActionDTO::class, $dto);
                }
                $this->handleUpdate($dto);

                break;

            case HilosSignalConstants::SETTING_DELETE:
                if (!$dto instanceof HilosSettingDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosSettingDeleteActionDTO::class, $dto);
                }
                $this->handleDelete($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Adds a setting through the settings table action.
     *
     * @param HilosSettingAddActionDTO $dto Add action payload
     * @throws TableActionException When the setting key is empty or the settings table is not registered
     * @throws DatabaseException When the active database context is not a HilosDbContext
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws HilosException When catalog validation or setting persistence fails
     */
    private function handleAdd(HilosSettingAddActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        $this->settingsTable()->actions->add($dto->key, $dto->value);
    }

    /**
     * Updates a setting through the settings table item action.
     *
     * @param HilosSettingUpdateActionDTO $dto Update action payload
     * @throws TableActionException When the setting key is empty, the table is not registered, or the setting is missing
     * @throws DatabaseException When settings persistence or row reload fails
     * @throws SettingException When catalog default metadata cannot rebuild the row
     */
    private function handleUpdate(HilosSettingUpdateActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        $this->settingsTable()[$dto->key]->actions->updateValue($dto->value);
    }

    /**
     * Deletes an orphan setting through the settings table item action.
     *
     * @param HilosSettingDeleteActionDTO $dto Delete action payload
     * @throws TableActionException When the setting key is empty, the table is not registered, missing, or still in the catalog
     * @throws DatabaseException When settings persistence fails
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     */
    private function handleDelete(HilosSettingDeleteActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        $this->settingsTable()[$dto->key]->actions->delete();
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
