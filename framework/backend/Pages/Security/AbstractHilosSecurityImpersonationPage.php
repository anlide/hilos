<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Auth\Impersonation\ImpersonationSettings;
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
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Security\DTO\HilosImpersonationScopeSetActionDTO;
use Hilos\Pages\Security\DTO\HilosImpersonationSwitchSetActionDTO;
use Hilos\Tables\Security\HilosSecurityImpersonationTable;

/**
 * AbstractHilosSecurityImpersonationPage - whether an administrator may act inside another person's account (HIL-1170).
 *
 * A child of the security hub. Shows the seven settings of impersonation
 * ({@see HilosSecurityImpersonationTable}): six switches - whether impersonation exists at all,
 * whether the sign-in of the account may be touched, whether the administrator carries their own
 * rights inside, and whether a blocked person, a frozen person and another administrator may be
 * taken over - and what may be done inside, only look or act as well, edited through a dialog.
 *
 * The values are application settings, so the writes go through {@see SettingsLibraryAgent}, the
 * single owner of the settings collection: this page keeps the ADMIN level and narrows the writes
 * to its own keys, and the scope's rule refuses a value in the dialog with the same words wherever
 * else it is written. The library also tells every open tab when the scope or the admin rights
 * moved, whichever door moved them, so this page sends nothing of its own.
 */
abstract class AbstractHilosSecurityImpersonationPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_SECURITY_IMPERSONATION;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::SECURITY_IMPERSONATION_SWITCH_SET => HilosImpersonationSwitchSetActionDTO::class,
        HilosSignalConstants::SECURITY_IMPERSONATION_SCOPE_SET => HilosImpersonationScopeSetActionDTO::class,
    ];

    /** The library's answer to the setting write this page forwarded, for either action. */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY_IMPERSONATION,
    ];

    /** Refusal for a switch that is not one of the six. */
    private const string REFUSAL_UNKNOWN_KEY = 'Unknown impersonation setting';

    /**
     * Routes the setting actions to their handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the library answers the tracked action when it has written
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the switch is not one of the six
     * @throws InvalidArgumentException When the write cannot be handed to the library
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::SECURITY_IMPERSONATION_SWITCH_SET:
                if (!$dto instanceof HilosImpersonationSwitchSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosImpersonationSwitchSetActionDTO::class, $dto);
                }
                $this->handleSwitchSet($acceptKey, $dto);
                return null;

            case HilosSignalConstants::SECURITY_IMPERSONATION_SCOPE_SET:
                if (!$dto instanceof HilosImpersonationScopeSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosImpersonationScopeSetActionDTO::class, $dto);
                }
                $this->handleScopeSet($acceptKey, $dto);
                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Answers the administrator whose setting write the library has finished.
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
        if ($name !== HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof HandoverAnswerSignalData) {
            throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
        }

        $this->answerHandover($data->data);
    }

    /**
     * Asks the owner of the settings collection to store one switch.
     *
     * Only the key is judged here - whether it is one of this page's switches. No success sentence:
     * the switch answers for itself by moving on screen.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosImpersonationSwitchSetActionDTO $dto Switch action payload
     * @throws TableActionException When the key is not one of the six switches
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function handleSwitchSet(string $acceptKey, HilosImpersonationSwitchSetActionDTO $dto): void
    {
        if (!in_array($dto->key, ImpersonationSettings::SWITCH_KEYS, true)) {
            throw new TableActionException(self::REFUSAL_UNKNOWN_KEY);
        }

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SECURITY_IMPERSONATION_SWITCH_SET,
                successMessage: null,
                key: $dto->key,
                value: $dto->enabled,
            ),
        );
    }

    /**
     * Asks the owner of the settings collection to store what may be done inside.
     *
     * The value is judged by the scope's own rule inside the write, so a refusal reads the same
     * wherever the value comes from.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosImpersonationScopeSetActionDTO $dto Scope action payload
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function handleScopeSet(string $acceptKey, HilosImpersonationScopeSetActionDTO $dto): void
    {
        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_IMPERSONATION_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SECURITY_IMPERSONATION_SCOPE_SET,
                successMessage: 'Impersonation setting saved.',
                key: ImpersonationSettings::SCOPE_KEY,
                value: $dto->scope,
            ),
        );
    }
}
