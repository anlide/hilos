<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Auth\StepUp\StepUpOperation;
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
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Hilos;
use Hilos\Pages\Security\DTO\HilosStepUpOperationSetActionDTO;
use Hilos\Tables\Security\HilosSecurityStepUpTable;

/**
 * AbstractHilosSecurityStepUpPage - the operations that ask for confirmation, a child of two-factor (HIL-1204).
 *
 * Shows every operation of the step-up directory ({@see HilosSecurityStepUpTable}) with its switch,
 * and writes a switch as the list of the operation's declared side. The lists are application
 * settings, so the write goes through {@see SettingsLibraryAgent}, the single owner of the settings
 * collection; this page keeps the ADMIN level and refuses an operation the directory does not declare.
 */
abstract class AbstractHilosSecurityStepUpPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_SECURITY_STEP_UP;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET => HilosStepUpOperationSetActionDTO::class,
    ];

    /** The library's answer to the operation-list write this page forwarded. */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_STEP_UP_OPERATIONS_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY_STEP_UP,
    ];

    /**
     * Routes the operation switch to its handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the library answers the tracked action when it has written
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the operation is not declared
     * @throws InvalidArgumentException When the write cannot be handed to the library
     * @throws DatabaseException When an operation list cannot be read
     * @throws SettingException When its setting catalog or stored value is invalid
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
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
     * Answers the administrator whose operation-list write the library has finished.
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
        if ($name !== HilosSignalConstants::HILOS_STEP_UP_OPERATIONS_WRITE_DONE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof HandoverAnswerSignalData) {
            throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
        }

        $this->answerHandover($data->data);
    }

    /**
     * Writes the list of the operation's declared position: departing from it lists the operation, returning takes it out.
     *
     * An operation declared on is listed among the switched-off, one declared off among the
     * switched-on (HIL-1275), so an operation added to the directory later stands where it was
     * declared, whatever was switched before it ({@see StepUpSettings}). The list keeps only
     * operations of its own side, in directory order.
     *
     * @param string $acceptKey Requesting administrator
     * @param HilosStepUpOperationSetActionDTO $dto Operation switch payload
     * @throws TableActionException When the operation is not declared
     * @throws InvalidArgumentException When the write cannot be handed to the library
     * @throws DatabaseException When the operation list cannot be read
     * @throws SettingException When its setting catalog or stored value is invalid
     */
    private function handleStepUpSet(string $acceptKey, HilosStepUpOperationSetActionDTO $dto): void
    {
        $operations = Hilos::stepUpOperationDirectoryClass()::all();
        $operation = $operations[$dto->operationKey] ?? throw new TableActionException("Unknown operation: {$dto->operationKey}");

        $listKey = StepUpSettings::listKeyFor($dto->operationKey);
        $listed = $listKey === StepUpSettings::DISABLED_KEY ? StepUpSettings::disabledKeys() : StepUpSettings::enabledKeys();
        $listed = $dto->enabled === $operation->enabledByDefault
            ? array_diff($listed, [$dto->operationKey])
            : [...$listed, $dto->operationKey];
        $side = array_keys(array_filter(
            $operations,
            static fn (StepUpOperation $declared): bool => $declared->enabledByDefault === $operation->enabledByDefault,
        ));

        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_STEP_UP_OPERATIONS_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::SECURITY_STEP_UP_OPERATION_SET,
                successMessage: null,
                key: $listKey,
                value: StepUpSettings::format(array_values(array_intersect($side, $listed))),
            ),
        );
    }
}
