<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Settings\Library\DTO\SettingDeleteSignalData;
use Hilos\Database\Settings\Library\DTO\SettingResetSignalData;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Tables\Settings\DTO\HilosSettingAddActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingDeleteActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingResetActionDTO;
use Hilos\Tables\Settings\DTO\HilosSettingUpdateActionDTO;

/**
 * Base class for the framework Hilos settings page.
 *
 * Owns the settings subscribe signal and the add/update/delete/reset action
 * lifecycle over the framework settings table. A project activates the feature by
 * extending this page with a `SUBSCRIPTION_AGENT_TYPE` and registering it; the
 * catalog binds to the settings facade (Hilos::$setting), not to the page.
 *
 * The four writes run in two steps since HIL-946, and the split is between the two things each
 * of them was doing at once: deciding WHO may ask, which is the ADMIN level this page carries
 * and nothing else does, and WRITING the row, which belongs to {@see SettingsLibraryAgent}. A
 * page runs in whichever worker serves the connection, so a page writing settings was a write
 * with no claim behind it - and settings were claimed by two agents at once, which is what the
 * move ends.
 *
 * Nothing on the browser wire changed: the four action names, their payloads and their acks are
 * what they were. What changed is that the ack now arrives a tick later, carried back from the
 * library over {@see HilosSignalConstants::HILOS_SETTING_WRITE_DONE}.
 */
abstract class AbstractHilosSettingsPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_SETTINGS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::SETTING_ADD => HilosSettingAddActionDTO::class,
        HilosSignalConstants::SETTING_UPDATE => HilosSettingUpdateActionDTO::class,
        HilosSignalConstants::SETTING_DELETE => HilosSettingDeleteActionDTO::class,
        HilosSignalConstants::SETTING_RESET => HilosSettingResetActionDTO::class,
    ];

    /**
     * The library's answer to whichever of the four writes this page forwarded (HIL-946).
     *
     * Declaring it here is what brings the answer back to the surface that asked: a page-owned
     * signal is routed to the agent serving this page, which hands it to this handler. One name
     * for all four, because there is one thing to say back - it is written, or here is why it
     * is not - and the ack is addressed by the action name the frame carries.
     *
     * The name is this page's own and is not shared with the other two screens that write
     * settings: the map of page-owned signals holds one entry per name, so two pages under one
     * name would overwrite each other without a word.
     */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_SETTING_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SETTINGS,
    ];

    /**
     * Routes setting add, update, delete, and reset actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the action names no setting key
     * @throws InvalidArgumentException When the write cannot be handed to the library
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::SETTING_ADD:
                if (!$dto instanceof HilosSettingAddActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosSettingAddActionDTO::class, $dto);
                }
                $this->handleAdd($acceptKey, $dto);

                break;

            case HilosSignalConstants::SETTING_UPDATE:
                if (!$dto instanceof HilosSettingUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosSettingUpdateActionDTO::class, $dto);
                }
                $this->handleUpdate($acceptKey, $dto);

                break;

            case HilosSignalConstants::SETTING_DELETE:
                if (!$dto instanceof HilosSettingDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosSettingDeleteActionDTO::class, $dto);
                }
                $this->handleDelete($acceptKey, $dto);

                break;

            case HilosSignalConstants::SETTING_RESET:
                if (!$dto instanceof HilosSettingResetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosSettingResetActionDTO::class, $dto);
                }
                $this->handleReset($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the administrator whose write the library has finished (HIL-946).
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
        if ($name !== HilosSignalConstants::HILOS_SETTING_WRITE_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof HandoverAnswerSignalData) {
            throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
        }

        $this->answerHandover($data->data);
    }

    /**
     * Asks the owner of the settings collection to put a value under a key.
     *
     * The empty key stays here, and everything about the row does not: whether the catalog
     * knows the key and whether a row already stands under it are read in one worker and acted
     * on in another, and the row is free to change in between. An empty key needs no row read
     * to be wrong.
     *
     * The sentence is composed here too, and travels along to be echoed back. It names the key
     * the administrator typed, which is a thing the screen has and the owner of the collection
     * has no business learning.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosSettingAddActionDTO $dto Add action payload
     * @throws TableActionException When the action names no setting key
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function handleAdd(string $acceptKey, HilosSettingAddActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SETTING_ADD,
                successMessage: "Setting \"{$dto->key}\" saved.",
                key: $dto->key,
                value: $dto->value,
            ),
        );
    }

    /**
     * Asks the owner of the settings collection to change the value under a key.
     *
     * The same ask as the add, and deliberately so: the library writes both the same idempotent
     * way, and two names for one write would only record which button was pressed.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosSettingUpdateActionDTO $dto Update action payload
     * @throws TableActionException When the action names no setting key
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function handleUpdate(string $acceptKey, HilosSettingUpdateActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SETTING_UPDATE,
                successMessage: "Setting \"{$dto->key}\" saved.",
                key: $dto->key,
                value: $dto->value,
            ),
        );
    }

    /**
     * Asks the owner of the settings collection to drop an orphan row.
     *
     * No sentence travels with it and none is spoken on the way back: the row leaves the table
     * in front of the administrator, which is what the gesture was for.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosSettingDeleteActionDTO $dto Delete action payload
     * @throws TableActionException When the action names no setting key
     * @throws InvalidArgumentException When the delete frame cannot be named or queued
     */
    private function handleDelete(string $acceptKey, HilosSettingDeleteActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_DELETE,
            new SettingDeleteSignalData(
                replySignal: HilosSignalConstants::HILOS_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SETTING_DELETE,
                successMessage: null,
                key: $dto->key,
            ),
        );
    }

    /**
     * Asks the owner of the settings collection to return a key to its catalog default.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosSettingResetActionDTO $dto Reset action payload
     * @throws TableActionException When the action names no setting key
     * @throws InvalidArgumentException When the reset frame cannot be named or queued
     */
    private function handleReset(string $acceptKey, HilosSettingResetActionDTO $dto): void
    {
        if ($dto->key === '') {
            throw new TableActionException('Setting key is required');
        }

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_RESET,
            new SettingResetSignalData(
                replySignal: HilosSignalConstants::HILOS_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SETTING_RESET,
                successMessage: "Setting \"{$dto->key}\" is back to its default.",
                key: $dto->key,
            ),
        );
    }
}
