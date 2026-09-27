<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

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
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Legal\LegalSettings;
use Hilos\Pages\Legal\DTO\HilosLegalSettingSetActionDTO;

/**
 * ADMIN gate for the two legal settings; their library owns the writes and their validation.
 */
abstract class AbstractHilosLegalSettingsPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_LEGAL_SETTINGS;
    public const PageReach REACH = PageReach::ROUTE;
    public const array ACTIONS = [
        HilosSignalConstants::LEGAL_SETTING_SET => HilosLegalSettingSetActionDTO::class,
    ];
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_LEGAL_SETTING_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_SETTINGS,
    ];

    /**
     * @param string $acceptKey Requesting administrator's connection
     * @param string $action Requested action name
     * @param ActionPayloadDTO $dto Parsed payload
     * @return ?ActionReplyDTO No immediate reply; the library answers the tracked action
     * @throws AgentUnknownActionException When the action is unsupported
     * @throws InvalidActionPayloadException When the DTO does not match its action
     * @throws TableActionException When the key belongs to another section
     * @throws InvalidArgumentException When the handover signal cannot be named
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::LEGAL_SETTING_SET:
                if (!$dto instanceof HilosLegalSettingSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosLegalSettingSetActionDTO::class, $dto);
                }
                $this->handleSet($acceptKey, $dto);
                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @param AgentSignalData $data Wrapped library answer
     * @param string $sender Signal origin, unused
     * @param string $name Routed signal name
     * @throws AgentUnknownSignalException When the signal is unsupported
     * @throws LogicException When the declared answer type is missing
     * @throws InvalidArgumentException When the action acknowledgement cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_LEGAL_SETTING_WRITE_DONE:
                if (!$data->data instanceof HandoverAnswerSignalData) {
                    throw new LogicException($name . ' payload must be ' . HandoverAnswerSignalData::class);
                }
                $this->answerHandover($data->data);
                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @param string $acceptKey Requesting administrator's connection
     * @param HilosLegalSettingSetActionDTO $dto Setting write request
     * @throws TableActionException When the key is not one of the two legal settings
     * @throws InvalidArgumentException When the library request cannot be named
     */
    private function handleSet(string $acceptKey, HilosLegalSettingSetActionDTO $dto): void
    {
        if (!in_array($dto->key, LegalSettings::KEYS, true)) {
            throw new TableActionException('Unknown legal setting');
        }
        $this->forward(
            HilosSignalConstants::HILOS_SETTING_WRITE,
            new SettingWriteSignalData(
                replySignal: HilosSignalConstants::HILOS_LEGAL_SETTING_WRITE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::LEGAL_SETTING_SET,
                successMessage: 'Legal setting saved.',
                key: $dto->key,
                value: $dto->value,
            ),
        );
    }
}
