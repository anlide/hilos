<?php

declare(strict_types=1);

namespace Hilos\Pages\Status;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\HilosException;
use Hilos\Pages\Status\DTO\HilosStatusAddUpdateActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusIncidentActionDTO;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusOverviewData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\StatusSettingsCatalog;

/** Complete Status overview and ADMIN gatekeeper for the existing Status writer. */
abstract class AbstractHilosStatusPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_STATUS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION => HilosStatusIncidentActionDTO::class,
        HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW => HilosStatusIncidentActionDTO::class,
        HilosSignalConstants::HILOS_STATUS_ADD_UPDATE => HilosStatusAddUpdateActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_STATUS_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS,
    ];

    public const array READS_DB = [
        ...parent::READS_DB, HilosDbContext::statusIncidents, HilosDbContext::statusIncidentUpdates, HilosDbContext::settings,
        HilosDbContext::statusSubscribers,
    ];

    public const array READS_RT = [...parent::READS_RT, HilosStatusComponent::RT_COLLECTION, ProtectedModeRuntime::RT_ITEM];

    /**
     * @param SourceChange $change Committed source fact
     * @return bool Whether this fact changes the complete overview
     */
    public static function observesOverviewChange(SourceChange $change): bool
    {
        if ($change->kind === SourceChange::KIND_RT) {
            return in_array($change->sourceKey, [HilosStatusComponent::RT_COLLECTION, ProtectedModeRuntime::RT_ITEM], true);
        }
        if ($change->kind !== SourceChange::KIND_DB) {
            return false;
        }
        if ($change->sourceKey === HilosDbContext::settings) {
            return !isset($change->row[Setting::key]) || $change->row[Setting::key] === StatusSettingsCatalog::PUBLISH_DELAY_SECONDS;
        }

        return in_array($change->sourceKey, [
            HilosDbContext::statusIncidents, HilosDbContext::statusIncidentUpdates, HilosDbContext::statusSubscribers,
        ], true);
    }

    /**
     * @param string $acceptKey Subscribing browser connection
     * @param PageRouteParams $params Empty route parameters
     * @return PagePayload Complete overview, including explicit unavailable values
     * @throws HilosException When source or catalog declarations are invalid
     * @throws SettingException When the delay setting catalog is invalid
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [StatusOverviewData::DATA => StatusOverviewData::read()->toArray()]);
    }

    /** @return array<string, WireField> Nested DB, settings and computed field origins */
    protected function dataFields(): array
    {
        return [StatusOverviewData::DATA => WireField::each(StatusOverviewData::wireFields())];
    }

    /**
     * @param string $acceptKey Initiating browser connection
     * @param string $action Original envelope action
     * @param ActionPayloadDTO $dto Typed action body
     * @return ?ActionReplyDTO No immediate reply; the writer answers the deferred action
     * @throws AgentUnknownActionException When the action is not declared here
     * @throws InvalidActionPayloadException When the action body has the wrong DTO
     * @throws InvalidArgumentException When forwarding cannot be queued
     * @throws InvalidFormatException When internal handover metadata is invalid
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION:
                if (!$dto instanceof HilosStatusIncidentActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosStatusIncidentActionDTO::class, $dto);
                }
                $this->handleCancelPublication($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW:
                if (!$dto instanceof HilosStatusIncidentActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosStatusIncidentActionDTO::class, $dto);
                }
                $this->handlePublishNow($acceptKey, $dto);

                break;

            case HilosSignalConstants::HILOS_STATUS_ADD_UPDATE:
                if (!$dto instanceof HilosStatusAddUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosStatusAddUpdateActionDTO::class, $dto);
                }
                $this->handleAddUpdate($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * @param AgentSignalData $data Writer's wrapped answer
     * @param string $sender Sending agent identity (unused)
     * @param string $name Page-owned reply name
     * @throws AgentUnknownSignalException When the reply name is unknown
     * @throws InvalidAgentSignalPayloadException When the reply has the wrong DTO
     * @throws InvalidArgumentException When the action answer cannot be queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_STATUS_WRITE_DONE:
                if (!$data->data instanceof HandoverAnswerSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, HandoverAnswerSignalData::class, $data->data);
                }
                $this->answerHandover($data->data);

                break;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @param string $acceptKey Initiating browser connection
     * @param HilosStatusIncidentActionDTO $dto Exact incident target
     * @throws InvalidArgumentException When the ask cannot be queued
     * @throws InvalidFormatException When handover metadata is invalid
     */
    private function handleCancelPublication(string $acceptKey, HilosStatusIncidentActionDTO $dto): void
    {
        $this->forward(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, new StatusCancelPublicationSignalData(
            incidentId: $dto->incidentId,
            replySignal: HilosSignalConstants::HILOS_STATUS_WRITE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->currentActionRequestId(),
            action: HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION,
            successMessage: 'Publication canceled.',
        ));
    }

    /**
     * @param string $acceptKey Initiating browser connection
     * @param HilosStatusIncidentActionDTO $dto Exact incident target
     * @throws InvalidArgumentException When the ask cannot be queued
     * @throws InvalidFormatException When handover metadata is invalid
     */
    private function handlePublishNow(string $acceptKey, HilosStatusIncidentActionDTO $dto): void
    {
        $this->forward(HilosSignalConstants::STATUS_PUBLISH_NOW, new StatusPublishNowSignalData(
            incidentId: $dto->incidentId,
            replySignal: HilosSignalConstants::HILOS_STATUS_WRITE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->currentActionRequestId(),
            action: HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW,
            successMessage: 'Outage published.',
        ));
    }

    /**
     * @param string $acceptKey Initiating browser connection
     * @param HilosStatusAddUpdateActionDTO $dto Exact target and plain draft
     * @throws InvalidArgumentException When the ask cannot be queued
     * @throws InvalidFormatException When handover metadata is invalid
     */
    private function handleAddUpdate(string $acceptKey, HilosStatusAddUpdateActionDTO $dto): void
    {
        $this->forward(HilosSignalConstants::STATUS_ADD_UPDATE, new StatusAddUpdateSignalData(
            incidentId: $dto->incidentId,
            text: $dto->text,
            replySignal: HilosSignalConstants::HILOS_STATUS_WRITE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->currentActionRequestId(),
            action: HilosSignalConstants::HILOS_STATUS_ADD_UPDATE,
            successMessage: 'Update published.',
        ));
    }
}
