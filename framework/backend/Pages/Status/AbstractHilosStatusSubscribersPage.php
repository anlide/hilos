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
use Hilos\HilosException;
use Hilos\Pages\Status\DTO\HilosStatusRemoveSubscriberActionDTO;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\DTO\StatusRemoveSubscriberSignalData;
use Hilos\Status\DTO\StatusSubscriberCountsData;

/** ADMIN subscriber viewport with complete initial counts and deferred owner-confirmed removal. */
abstract class AbstractHilosStatusSubscribersPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_STATUS_SUBSCRIBERS;
    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE => HilosStatusRemoveSubscriberActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_SUBSCRIBERS,
    ];

    public const array READS_DB = [...parent::READS_DB, HilosDbContext::statusSubscribers];
    public const array READS_RT = [...parent::READS_RT, ProtectedModeRuntime::RT_ITEM];

    /**
     * @param SourceChange $change Committed subscription or freeze fact
     * @return bool Whether the page's count projection must be rebuilt
     */
    public static function observesOverviewChange(SourceChange $change): bool
    {
        return $change->isDb() && $change->sourceKey === HilosDbContext::statusSubscribers
            || $change->isRt() && $change->sourceKey === ProtectedModeRuntime::RT_ITEM;
    }

    /**
     * @param string $acceptKey Initiating browser connection
     * @param string $action Original action name
     * @param ActionPayloadDTO $dto Parsed target
     * @return ?ActionReplyDTO No immediate reply; the owner answers after its commit
     * @throws AgentUnknownActionException When the action is undeclared
     * @throws InvalidActionPayloadException When the target has the wrong DTO
     * @throws InvalidArgumentException When the ask cannot be queued
     * @throws InvalidFormatException When handover metadata is malformed
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE:
                if (!$dto instanceof HilosStatusRemoveSubscriberActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosStatusRemoveSubscriberActionDTO::class, $dto);
                }
                $this->handleRemove($acceptKey, $dto);
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
            case HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE:
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
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Empty route parameters
     * @return PagePayload Counts alongside the ordinary browser table/window payload
     * @throws HilosException When the source declaration or aggregate contract is invalid
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [StatusSubscriberCountsData::DATA => StatusSubscriberCountsData::read()?->toArray()]);
    }

    /** @return array<string, WireField> Aggregate values remain visible to viewers */
    protected function dataFields(): array
    {
        return [StatusSubscriberCountsData::DATA => WireField::each(StatusSubscriberCountsData::wireFields())];
    }

    /**
     * @param string $acceptKey Initiating browser connection
     * @param HilosStatusRemoveSubscriberActionDTO $dto Exact persisted target
     * @throws InvalidArgumentException When the ask cannot be queued
     * @throws InvalidFormatException When handover metadata is malformed
     */
    private function handleRemove(string $acceptKey, HilosStatusRemoveSubscriberActionDTO $dto): void
    {
        $this->forward(HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER, new StatusRemoveSubscriberSignalData(
            subscriberId: $dto->subscriberId,
            replySignal: HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->currentActionRequestId(),
            action: HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE,
            successMessage: 'Subscriber removed.',
        ));
    }
}
