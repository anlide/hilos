<?php

declare(strict_types=1);

namespace Demo\Chat\Projection\Page;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Frontend\AttachmentDraftFrontendStateProjector;
use Demo\Chat\Frontend\BotFrontendStateProjector;
use Demo\Chat\Frontend\SelfConnectionFrontendStateProjector;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Projection\Util\UserPresenceDeliveryBuilder;
use Demo\Chat\Runtime\State\Item\AttachmentDraft as StateAttachmentDraft;
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\PageProjection;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\Rule\ConnectionLocalRule;
use Hilos\Core\Projection\Rule\JoinedProjectionRule;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Projects main chat page snapshots and connection-local frontend updates.
 */
final class MainPageProjection extends PageProjection
{
    /**
     * Returns the main chat page key.
     *
     * @return string Page key for the main chat route
     */
    public function page(): string
    {
        return PageConstants::MAIN;
    }

    /**
     * Returns the subscribe snapshot signal for the main chat page.
     *
     * @return string Signal name for the initial main page snapshot
     */
    public function subscribeSnapshotSignalName(): string
    {
        return ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN;
    }

    /**
     * Registers connection-local frontend state and global chat snapshot rules.
     *
     * @return iterable<ConnectionLocalRule|JoinedProjectionRule>
     */
    protected function rules(): iterable
    {
        yield new ConnectionLocalRule(
            triggers: [RtChatContext::connections],
            snapshotForAcceptKey: static function (string $acceptKey): FrontendChangesDTO {
                if (Hilos::$rt === null || !isset(Hilos::$rt->connections[$acceptKey])) {
                    return new FrontendChangesDTO();
                }
                $connection = Hilos::$rt->connections[$acceptKey];
                return SelfConnectionFrontendStateProjector::appendFullForConnection(
                    AttachmentDraftFrontendStateProjector::fullForConnection($connection),
                    $connection,
                );
            },
            broadcast: function (SourceChange $change, array $audienceAcceptKeys): iterable {
                yield from $this->buildSelfConnectionDeliveriesOnConnectionChange($change, $audienceAcceptKeys);
                yield from $this->buildUserPresenceDeliveriesOnConnectionChange($change, $audienceAcceptKeys);
            },
        );

        yield new ConnectionLocalRule(
            triggers: [RtChatContext::userStates],
            snapshotForAcceptKey: static fn(): FrontendChangesDTO => new FrontendChangesDTO(),
            broadcast: function (SourceChange $change, array $audienceAcceptKeys): iterable {
                yield from $this->buildSelfConnectionDeliveriesOnUserStateChange($change, $audienceAcceptKeys);
            },
        );

        yield new ConnectionLocalRule(
            triggers: [RtChatContext::attachmentDrafts],
            snapshotForAcceptKey: static fn(): FrontendChangesDTO => new FrontendChangesDTO(),
            broadcast: function (SourceChange $change, array $audienceAcceptKeys): iterable {
                yield from $this->buildSelfConnectionDeliveriesOnAttachmentDraftChange($change, $audienceAcceptKeys);
            },
        );

        yield new JoinedProjectionRule(
            triggers: [],
            snapshotChanges: static function (string $acceptKey): FrontendChangesDTO {
                if (Hilos::$db === null || Hilos::$rt === null) {
                    return new FrontendChangesDTO();
                }
                return BotFrontendStateProjector::appendFullForBots(
                    UserFrontendStateProjector::fullForUsers(Hilos::$rt->connections->relevantUsers),
                    Hilos::$db->bots->activeOnly,
                );
            },
            broadcast: static fn(): iterable => [],
        );
    }

    /**
     * Wraps main-page entity and frontend state into the subscribe payload.
     *
     * @param SubscribeSnapshotAccumulator $accumulator Full frontend state collected from page rules
     * @param string $acceptKey Subscribing WebSocket accept key used to verify the RT connection
     * @param PageRouteParams $params Unused route params; this page has no params
     * @return ChatEventSignalDTO Main page subscribe snapshot payload
     * @throws PageInternalErrorException When DB, RT, or the subscriber connection is unavailable
     */
    protected function wrapSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): ?SignalDataInterface {
        if (Hilos::$rt === null || Hilos::$db === null) {
            throw new PageInternalErrorException('No RT/DB context for main page subscribe');
        }
        $connection = Hilos::$rt->connections[$acceptKey]
            ?? throw new PageInternalErrorException('No RT connection for this subscribe acceptKey');

        return new ChatEventSignalDTO(
            entities: new EntitiesChangesDTO(
                full: [
                    DbChatContext::bots => Hilos::$db->bots->activeOnly,
                    DbChatContext::events => Hilos::$db->events,
                ],
            ),
            frontend: $accumulator->buildFrontendChanges(),
        );
    }

    /**
     * Builds self-connection updates for moderation and upload fields.
     *
     * @param SourceChange $change Connection source change recorded in this worker
     * @param list<string> $audienceAcceptKeys Accept keys currently subscribed to the main page
     * @return iterable<ProjectionDelivery> Self-connection update deliveries
     */
    private function buildSelfConnectionDeliveriesOnConnectionChange(
        SourceChange $change,
        array $audienceAcceptKeys,
    ): iterable {
        foreach ([
            StateConnection::outboundModerationPhase,
            StateConnection::outboundModerationMessage,
            StateConnection::outboundModerationReason,
            StateConnection::outboundModerationUpdatedAt,
            StateConnection::fileUploadPhase,
            StateConnection::fileUploadClientUploadId,
            StateConnection::fileUploadErrorCode,
            StateConnection::fileUploadErrorMessage,
            StateConnection::uploadProgressLastSentAt,
        ] as $field) {
            if (array_key_exists($field, $change->row)) {
                $relevant = true;
                break;
            }
        }
        if (!isset($relevant)
            || Hilos::$rt === null
            || !isset(Hilos::$rt->connections[$change->sourceId])
            || !in_array($change->sourceId, $audienceAcceptKeys, true)
        ) {
            return;
        }

        yield new ProjectionDelivery(
            ChatSignalConstants::SELF_CONNECTION_UPDATE,
            SelfConnectionSignalData::fromFrontendChanges(
                SelfConnectionFrontendStateProjector::fullForConnection(Hilos::$rt->connections[$change->sourceId]),
            ),
            $change->sourceId,
        );
    }

    /**
     * Builds public user presence updates for a connection change.
     *
     * @param SourceChange $change Connection source change recorded in this worker
     * @param list<string> $audienceAcceptKeys Accept keys currently subscribed to the main page
     * @return iterable<ProjectionDelivery> User presence update deliveries
     */
    private function buildUserPresenceDeliveriesOnConnectionChange(
        SourceChange $change,
        array $audienceAcceptKeys,
    ): iterable {
        $payload = UserPresenceDeliveryBuilder::buildForConnectionChange(
            change: $change,
            includeConnectionStats: false,
        );
        if ($payload === null) {
            return;
        }
        foreach (array_values(array_unique($audienceAcceptKeys)) as $acceptKey) {
            if ($acceptKey === '') {
                continue;
            }
            yield new ProjectionDelivery(
                ChatSignalConstants::USER_PRESENCE_UPDATE,
                $payload,
                $acceptKey,
            );
        }
    }

    /**
     * Builds self-connection updates when a user's outbound submit timer changes.
     *
     * @param SourceChange $change User-state source change recorded in this worker
     * @param list<string> $audienceAcceptKeys Accept keys currently subscribed to the main page
     * @return iterable<ProjectionDelivery> Self-connection update deliveries
     */
    private function buildSelfConnectionDeliveriesOnUserStateChange(
        SourceChange $change,
        array $audienceAcceptKeys,
    ): iterable {
        if (
            !array_key_exists(StateChatUserState::lastOutboundSubmittedAt, $change->row)
            || Hilos::$rt === null
        ) {
            return;
        }
        $userId = (int)($change->row[StateChatUserState::userId] ?? $change->sourceId);
        if ($userId <= 0) {
            return;
        }

        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            if (!in_array($connection->acceptKey, $audienceAcceptKeys, true)) {
                continue;
            }
            yield new ProjectionDelivery(
                ChatSignalConstants::SELF_CONNECTION_UPDATE,
                SelfConnectionSignalData::fromFrontendChanges(
                    SelfConnectionFrontendStateProjector::fullForConnection($connection),
                ),
                $connection->acceptKey,
            );
        }
    }

    /**
     * Builds self-connection updates when attachment drafts change.
     *
     * @param SourceChange $change Attachment-draft source change recorded in this worker
     * @param list<string> $audienceAcceptKeys Accept keys currently subscribed to the main page
     * @return iterable<ProjectionDelivery> Self-connection update deliveries
     */
    private function buildSelfConnectionDeliveriesOnAttachmentDraftChange(
        SourceChange $change,
        array $audienceAcceptKeys,
    ): iterable {
        if (Hilos::$rt === null) {
            return;
        }
        $acceptKey = (string)($change->row[StateAttachmentDraft::acceptKey] ?? '');
        if ($acceptKey === '') {
            $acceptKey = Hilos::$rt->attachmentDrafts[$change->sourceId]?->acceptKey ?? '';
        }
        if ($acceptKey === '' || !in_array($acceptKey, $audienceAcceptKeys, true)) {
            return;
        }
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }

        yield new ProjectionDelivery(
            ChatSignalConstants::SELF_CONNECTION_UPDATE,
            SelfConnectionSignalData::fromFrontendChanges(
                AttachmentDraftFrontendStateProjector::fullForConnection(Hilos::$rt->connections[$acceptKey]),
            ),
            $acceptKey,
        );
    }
}
