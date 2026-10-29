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
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\DTO\StatusWorksData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Pages\Status\DTO\HilosStatusScheduleWorkActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusChangeWorkActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusCancelWorkActionDTO;
use Hilos\Status\DTO\StatusScheduleWorkSignalData;
use Hilos\Status\DTO\StatusChangeWorkSignalData;
use Hilos\Status\DTO\StatusCancelWorkSignalData;

/**
 * ADMIN work actions; only the Status owner writes and confirms their outcome.
 */
abstract class AbstractHilosStatusWorksPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_STATUS_WORKS;
    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE => HilosStatusScheduleWorkActionDTO::class,
        HilosSignalConstants::HILOS_STATUS_WORK_CHANGE => HilosStatusChangeWorkActionDTO::class,
        HilosSignalConstants::HILOS_STATUS_WORK_CANCEL => HilosStatusCancelWorkActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_WORKS,
    ];

    public const array READS_DB = [...parent::READS_DB, HilosDbContext::statusWorks, HilosDbContext::statusWorkComponents];
    public const array READS_RT = [...parent::READS_RT, ProtectedModeRuntime::RT_ITEM];

    /**
     * @param SourceChange $change Committed work, bridge or freeze fact
     * @return bool Whether the complete form and summary must be rebuilt
     */
    public static function observesOverviewChange(SourceChange $change): bool
    {
        return $change->isDb() && in_array($change->sourceKey, [HilosDbContext::statusWorks, HilosDbContext::statusWorkComponents], true)
            || $change->isRt() && $change->sourceKey === ProtectedModeRuntime::RT_ITEM;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Empty route parameters
     * @return PagePayload Form catalog and summary beside the ordinary initial viewport
     * @throws HilosException When source declarations or catalog contracts are invalid
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [StatusWorksData::DATA => StatusWorksData::read()?->toArray()]);
    }

    /** @return array<string, WireField> All permitted nonpersonal form and summary fields */
    protected function dataFields(): array
    {
        return [StatusWorksData::DATA => WireField::each(StatusWorksData::wireFields())];
    }

    /**
     * @param string $acceptKey Initiating connection
     * @param string $action Declared page action
     * @param ActionPayloadDTO $dto Strict business payload
     * @return ?ActionReplyDTO Deferred owner-confirmed outcome
     * @throws AgentUnknownActionException When the action is undeclared
     * @throws InvalidActionPayloadException When the action DTO does not match
     * @throws InvalidArgumentException When the ask cannot be queued
     * @throws InvalidFormatException When handover metadata is malformed
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE:
                if (!$dto instanceof HilosStatusScheduleWorkActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosStatusScheduleWorkActionDTO::class, $dto);
                }
                $this->handleSchedule($acceptKey, $dto);
                return null;

            case HilosSignalConstants::HILOS_STATUS_WORK_CHANGE:
                if (!$dto instanceof HilosStatusChangeWorkActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosStatusChangeWorkActionDTO::class, $dto);
                }
                $this->handleChange($acceptKey, $dto);
                return null;

            case HilosSignalConstants::HILOS_STATUS_WORK_CANCEL:
                if (!$dto instanceof HilosStatusCancelWorkActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosStatusCancelWorkActionDTO::class, $dto);
                }
                $this->handleCancel($acceptKey, $dto);
                return null;

            default:
                throw new AgentUnknownActionException($action);
        }
    }

    /**
     * @param AgentSignalData $data Wrapped owner outcome
     * @param string $sender Owner address (unused)
     * @param string $name Page-owned answer signal
     * @throws AgentUnknownSignalException When the answer name is undeclared
     * @throws InvalidAgentSignalPayloadException When the answer has the wrong DTO
     * @throws InvalidArgumentException When the client answer cannot be queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE:
                if (!$data->data instanceof HandoverAnswerSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, HandoverAnswerSignalData::class, $data->data);
                }
                $this->answerHandover($data->data);
                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @param string $acceptKey Initiating connection
     * @param HilosStatusScheduleWorkActionDTO $dto Business payload without envelope metadata
     * @throws InvalidArgumentException When the owner ask cannot be queued
     * @throws InvalidFormatException When handover metadata is malformed
     */
    private function handleSchedule(string $acceptKey, HilosStatusScheduleWorkActionDTO $dto): void
    {
        $this->forward(HilosSignalConstants::STATUS_SCHEDULE_WORK, new StatusScheduleWorkSignalData(
            title: $dto->title,
            description: $dto->description,
            componentKeys: $dto->componentKeys,
            startsAt: $dto->startsAt,
            endsAt: $dto->endsAt,
            replySignal: HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->currentActionRequestId(),
            action: HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE,
            successMessage: 'Maintenance scheduled.',
        ));
    }

    /**
     * @param string $acceptKey Initiating connection
     * @param HilosStatusChangeWorkActionDTO $dto Business payload without envelope metadata
     * @throws InvalidArgumentException When the owner ask cannot be queued
     * @throws InvalidFormatException When handover metadata is malformed
     */
    private function handleChange(string $acceptKey, HilosStatusChangeWorkActionDTO $dto): void
    {
        $this->forward(HilosSignalConstants::STATUS_CHANGE_WORK, new StatusChangeWorkSignalData(
            workId: $dto->workId,
            publicId: $dto->publicId,
            expectedVersion: $dto->expectedVersion,
            title: $dto->title,
            description: $dto->description,
            componentKeys: $dto->componentKeys,
            startsAt: $dto->startsAt,
            endsAt: $dto->endsAt,
            replySignal: HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->currentActionRequestId(),
            action: HilosSignalConstants::HILOS_STATUS_WORK_CHANGE,
            successMessage: 'Maintenance changed.',
        ));
    }

    /**
     * @param string $acceptKey Initiating connection
     * @param HilosStatusCancelWorkActionDTO $dto Business payload without envelope metadata
     * @throws InvalidArgumentException When the owner ask cannot be queued
     * @throws InvalidFormatException When handover metadata is malformed
     */
    private function handleCancel(string $acceptKey, HilosStatusCancelWorkActionDTO $dto): void
    {
        $this->forward(HilosSignalConstants::STATUS_CANCEL_WORK, new StatusCancelWorkSignalData(
            workId: $dto->workId,
            publicId: $dto->publicId,
            expectedVersion: $dto->expectedVersion,
            replySignal: HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->currentActionRequestId(),
            action: HilosSignalConstants::HILOS_STATUS_WORK_CANCEL,
            successMessage: 'Maintenance canceled.',
        ));
    }
}
