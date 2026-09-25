<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\StepUp\StepUpSettings;
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
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Pages\Security\DTO\HilosSecondFactorSettingSetActionDTO;
use Hilos\Pages\Security\DTO\HilosStepUpOperationSetActionDTO;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;

/**
 * AbstractHilosSecurityTwoFactorPage - the two-factor settings of the installation (HIL-494).
 *
 * Shows the six settings of the second factor ({@see HilosSecurityTwoFactorTable}) - who must use
 * it, how long a browser may be trusted, how many backup codes a set holds, and the bounds of the
 * removal wait - and edits each through a dialog. The values are application settings, so the
 * writes go through {@see SettingsLibraryAgent}, the single owner of the settings collection: this
 * page keeps the ADMIN level and narrows the write to its own six keys, and each key's rule refuses
 * a value in the dialog with the same words wherever else it is written.
 *
 * The protected-operation section is backed by the same settings owner and table fan-out.
 */
abstract class AbstractHilosSecurityTwoFactorPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_SECURITY_2FA;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::SECURITY_2FA_SETTING_SET => HilosSecondFactorSettingSetActionDTO::class,
        HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET => HilosStepUpOperationSetActionDTO::class,
    ];

    /** The library's answer to the setting write this page forwarded. */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETTING_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY_2FA,
    ];

    /** Refusal for a key that is not one of the six. */
    private const string REFUSAL_UNKNOWN_KEY = 'Unknown two-factor setting';

    /**
     * Routes the setting action to its handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the library answers the tracked action when it has written
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the key is not one of the six
     * @throws InvalidArgumentException When the write cannot be handed to the library
     * @throws DatabaseException When the disabled operation list cannot be read
     * @throws SettingException When its setting catalog or stored value is invalid
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::SECURITY_2FA_SETTING_SET:
                if (!$dto instanceof HilosSecondFactorSettingSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosSecondFactorSettingSetActionDTO::class, $dto);
                }
                $this->handleSet($acceptKey, $dto);
                return null;

            case HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET:
                if (!$dto instanceof HilosStepUpOperationSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosStepUpOperationSetActionDTO::class, $dto);
                }
                $this->handleStepUpSet($acceptKey, $dto);
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
        if ($name !== HilosSignalConstants::HILOS_SECOND_FACTOR_SETTING_WRITE_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof HandoverAnswerSignalData) {
            throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
        }

        $this->answerHandover($data->data);
    }

    /**
     * Asks the owner of the settings collection to store one of the six settings.
     *
     * Only the key is judged here - whether it is one of this page's; the value is judged by the
     * key's own rule inside the write, so a refusal reads the same wherever the value comes from.
     *
     * @param string $acceptKey WebSocket accept key of the requesting administrator
     * @param HilosSecondFactorSettingSetActionDTO $dto Set action payload
     * @throws TableActionException When the key is not one of the six
     * @throws InvalidArgumentException When the write frame cannot be named or queued
     */
    private function handleSet(string $acceptKey, HilosSecondFactorSettingSetActionDTO $dto): void
    {
        if (!in_array($dto->key, SecondFactorSettings::KEYS, true)) {
            throw new TableActionException(self::REFUSAL_UNKNOWN_KEY);
        }

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_SECOND_FACTOR_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SECURITY_2FA_SETTING_SET,
                successMessage: 'Two-factor setting saved.',
                key: $dto->key,
                value: $dto->key === SecondFactorSettings::REQUIRED_KEY ? $dto->value : $this->wholeNumber($dto->value),
            ),
        );
    }

    /**
     * @param string $acceptKey Requesting administrator
     * @param HilosStepUpOperationSetActionDTO $dto Operation switch payload
     * @throws TableActionException When the operation is not declared
     * @throws InvalidArgumentException When the write cannot be handed to the library
     * @throws DatabaseException When the disabled operation list cannot be read
     * @throws SettingException When its setting catalog or stored value is invalid
     */
    private function handleStepUpSet(string $acceptKey, HilosStepUpOperationSetActionDTO $dto): void
    {
        $keys = Hilos::stepUpOperationDirectoryClass()::keys();
        if (!in_array($dto->operationKey, $keys, true)) {
            throw new TableActionException("Unknown operation: {$dto->operationKey}");
        }

        $disabled = StepUpSettings::disabledKeys();
        $disabled = $dto->enabled
            ? array_diff($disabled, [$dto->operationKey])
            : [...$disabled, $dto->operationKey];

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_SECOND_FACTOR_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET,
                successMessage: null,
                key: StepUpSettings::DISABLED_KEY,
                value: StepUpSettings::format(array_values(array_intersect($keys, $disabled))),
            ),
        );
    }

    /**
     * Reads a typed number, leaving anything else to the rule that refuses it.
     *
     * @param string $value Value as typed
     * @return int|string The number, or the text as typed when it is none
     */
    private function wholeNumber(string $value): int|string
    {
        return SecondFactorSettings::wholeNumber($value) ?? $value;
    }
}
