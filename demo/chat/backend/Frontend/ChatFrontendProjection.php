<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\AttachmentDraftsUpdateSignalData;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\OutboundModerationStateUpdateSignalData;
use Demo\Chat\Core\Router\DTO\UserPresenceSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\AttachmentDraft as StateAttachmentDraft;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\State\Item\GuardianAgentStatus as StateGuardianAgentStatus;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Frontend\FrontendDelivery;
use Hilos\Core\Frontend\FrontendProjectionContext;
use Hilos\Core\Frontend\SourceChange;
use Hilos\Core\Frontend\SourceChangeSet;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;

/**
 * Chat demo projection from backend source facts to frontend wire payloads.
 *
 * The input facts are DB_SYNC/RT_SYNC rows collected by the worker. This class
 * is the only place that knows how chat DB/RT state maps to page-specific
 * frontend collections and table mutations.
 */
final class ChatFrontendProjection extends FrontendProjectionContext
{
    /**
     * @return iterable<FrontendDelivery>
     */
    protected function buildDeliveries(SourceChangeSet $changes): iterable
    {
        $deliveredEventIds = $this->eventIdsDeliveredByChanges($changes);
        foreach ($changes->all() as $change) {
            yield from $this->buildDeliveriesForChange($change, $deliveredEventIds);
        }
    }

    /**
     * @param array<int, true> $deliveredEventIds Events already projected by direct event changes
     * @return iterable<FrontendDelivery>
     */
    private function buildDeliveriesForChange(SourceChange $change, array $deliveredEventIds): iterable
    {
        if ($change->kind === SourceChange::KIND_DB) {
            yield from $this->buildDbDeliveries($change, $deliveredEventIds);
            return;
        }

        if ($change->kind === SourceChange::KIND_RT) {
            yield from $this->buildRtDeliveries($change);
        }
    }

    /**
     * @param array<int, true> $deliveredEventIds Events already projected by direct event changes
     * @return iterable<FrontendDelivery>
     */
    private function buildDbDeliveries(SourceChange $change, array $deliveredEventIds): iterable
    {
        if ($change->sourceKey === DbChatContext::events) {
            yield from $this->buildEventDeliveries($change);
            return;
        }

        if ($change->sourceKey === DbChatContext::eventAttachments) {
            yield from $this->buildEventAttachmentDeliveries($change, $deliveredEventIds);
            return;
        }

        if ($change->sourceKey === DbChatContext::users) {
            yield from $this->buildUserTableDeliveries($change);
            return;
        }

        if ($change->sourceKey === DbChatContext::bots) {
            yield from $this->buildBotDeliveries($change);
            return;
        }

        if ($change->sourceKey === DbChatContext::moderatorPromptPieces) {
            yield from $this->buildSingleTableDeliveries(
                $change,
                TableChatContext::moderatorPromptPieces,
                PageConstants::ADMIN_MODERATOR,
            );
            return;
        }

        if ($change->sourceKey === HilosDbContext::settings) {
            yield from $this->buildSettingsTableDeliveries($change);
        }
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function buildRtDeliveries(SourceChange $change): iterable
    {
        if ($change->sourceKey === RtChatContext::connections) {
            yield from $this->buildConnectionDeliveries($change);
            return;
        }

        if ($change->sourceKey === RtChatContext::userStates) {
            yield from $this->buildUserStateDeliveries($change);
            return;
        }

        if ($change->sourceKey === RtChatContext::attachmentDrafts) {
            yield from $this->buildAttachmentDraftDeliveries($change);
            return;
        }

        if ($change->sourceKey === RtChatContext::botAgentStatuses) {
            yield from $this->buildBotAgentStatusDeliveries($change);
            return;
        }

        if ($change->sourceKey === RtChatContext::guardianAgentStatuses) {
            yield from $this->buildGuardianAgentStatusDeliveries($change);
        }
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function buildEventDeliveries(SourceChange $change): iterable
    {
        if ($change->mutationType !== TableMutationType::Create) {
            return;
        }

        yield from $this->buildEventDeliveryById((int)$change->sourceId);
    }

    /**
     * @param array<int, true> $deliveredEventIds Events already projected by direct event changes
     * @return iterable<FrontendDelivery>
     */
    private function buildEventAttachmentDeliveries(SourceChange $change, array $deliveredEventIds): iterable
    {
        if ($change->mutationType === TableMutationType::Delete) {
            return;
        }

        $eventId = $this->eventIdFromAttachmentChange($change);
        if ($eventId <= 0 || isset($deliveredEventIds[$eventId])) {
            return;
        }

        yield from $this->buildEventDeliveryById($eventId);
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function buildEventDeliveryById(int $eventId): iterable
    {
        if ($eventId <= 0 || Hilos::$db === null) {
            return;
        }

        $event = Hilos::$db->events[$eventId] ?? null;
        if ($event === null) {
            return;
        }

        $payload = new ChatEventSignalDTO(
            new EntitiesChangesDTO(
                full: [DbChatContext::events => Events::fromSingleItem($event)],
                replaceFullKeys: $event->type === ChatEventType::CHAT_CLEARED->value ? [DbChatContext::events] : [],
            ),
            frontend: $this->frontendUpdatesForEventUser($event->type, $event->userId),
        );

        yield from $this->deliverToAcceptKeys(
            $this->allLocalAcceptKeys(),
            ChatSignalConstants::NEW_EVENT,
            $payload,
        );
    }

    /**
     * @return array<int, true>
     */
    private function eventIdsDeliveredByChanges(SourceChangeSet $changes): array
    {
        $eventIds = [];
        foreach ($changes->all() as $change) {
            if (
                $change->kind === SourceChange::KIND_DB
                && $change->sourceKey === DbChatContext::events
                && $change->mutationType === TableMutationType::Create
            ) {
                $eventIds[(int)$change->sourceId] = true;
            }
        }

        return $eventIds;
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function buildUserTableDeliveries(SourceChange $change): iterable
    {
        yield from $this->buildTableDeliveries(
            new TableSourceEventDTO(
                sourceKey: DbChatContext::users,
                sourceRowKey: (int)$change->sourceId,
                mutationType: $change->mutationType,
            ),
            [
                TableChatContext::adminUsers => PageConstants::ADMIN_USERS,
                TableChatContext::hilosUsers => HilosPageConstants::HILOS_USERS,
            ],
        );
    }

    /**
     * User state changes drive the connection-local outbound moderation composer state.
     *
     * @return iterable<FrontendDelivery>
     */
    private function buildUserStateDeliveries(SourceChange $change): iterable
    {
        if (!$this->isOutboundModerationChange($change) || Hilos::$rt === null) {
            return;
        }

        $acceptKey = $this->outboundModerationAcceptKeyForChange($change);
        if (!$this->isAcceptKeyOnPage($acceptKey, PageConstants::MAIN)) {
            return;
        }

        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($connection === null) {
            return;
        }

        yield new FrontendDelivery(
            ChatSignalConstants::OUTBOUND_MODERATION_STATE_UPDATE,
            new OutboundModerationStateUpdateSignalData(
                OutboundModerationStateProjector::forConnection($connection),
            ),
            $acceptKey,
        );
    }

    /**
     * Attachment draft changes update the connection-local draft list and any visible retry state.
     *
     * @return iterable<FrontendDelivery>
     */
    private function buildAttachmentDraftDeliveries(SourceChange $change): iterable
    {
        if (Hilos::$rt === null) {
            return;
        }

        $acceptKey = $this->attachmentDraftAcceptKeyForChange($change);
        if (!$this->isAcceptKeyOnPage($acceptKey, PageConstants::MAIN)) {
            return;
        }

        yield new FrontendDelivery(
            ChatSignalConstants::ATTACHMENT_DRAFTS_UPDATE,
            new AttachmentDraftsUpdateSignalData(
                Hilos::$rt->attachmentDrafts->toFrontendListForAcceptKey($acceptKey),
            ),
            $acceptKey,
        );

        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        $userState = $connection?->userState;
        if (
            $userState instanceof ChatUserState
            && $userState->outboundModerationPhase !== ChatUserState::OUTBOUND_MODERATION_PHASE_NONE
            && $userState->outboundModerationAcceptKey === $acceptKey
        ) {
            yield new FrontendDelivery(
                ChatSignalConstants::OUTBOUND_MODERATION_STATE_UPDATE,
                new OutboundModerationStateUpdateSignalData(
                    OutboundModerationStateProjector::forConnection($connection),
                ),
                $acceptKey,
            );
        }
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function buildBotDeliveries(SourceChange $change): iterable
    {
        yield from $this->buildSingleTableDeliveries($change, TableChatContext::bots, PageConstants::ADMIN_BOTS);

        if ($change->mutationType === TableMutationType::Delete || Hilos::$db === null) {
            return;
        }

        $botId = (int)$change->sourceId;
        if ($botId <= 0) {
            return;
        }

        $bot = Hilos::$db->bots[$botId] ?? null;
        if ($bot === null) {
            return;
        }

        yield from $this->deliverToAcceptKeys(
            $this->allLocalAcceptKeys(),
            ChatSignalConstants::BOT_UPDATED,
            new ChatEventSignalDTO(new EntitiesChangesDTO(updates: [
                DbChatContext::bots => [$bot->toArray(toFrontend: true)],
            ])),
        );
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function buildSettingsTableDeliveries(SourceChange $change): iterable
    {
        $sourceRowKey = $this->settingSourceRowKey($change);
        if ($sourceRowKey === '') {
            return;
        }

        yield from $this->buildTableDeliveries(
            new TableSourceEventDTO(
                sourceKey: HilosDbContext::settings,
                sourceRowKey: $sourceRowKey,
                mutationType: $change->mutationType,
            ),
            [TableChatContext::settings => HilosPageConstants::HILOS_SETTINGS],
        );
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function buildSingleTableDeliveries(SourceChange $change, string $tableKey, string $page): iterable
    {
        yield from $this->buildTableDeliveries(
            new TableSourceEventDTO(
                sourceKey: $change->sourceKey,
                sourceRowKey: (int)$change->sourceId,
                mutationType: $change->mutationType,
            ),
            [$tableKey => $page],
        );
    }

    /**
     * Connection source changes affect both user frontend state and user-backed table rows.
     *
     * @return iterable<FrontendDelivery>
     */
    private function buildConnectionDeliveries(SourceChange $change): iterable
    {
        if (
            $change->mutationType === TableMutationType::Update
            && !array_key_exists(StateConnection::userId, $change->row)
        ) {
            return;
        }

        $userId = $this->userIdFromConnectionChange($change);
        if ($userId <= 0 || Hilos::$db === null) {
            return;
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            return;
        }

        yield from $this->deliverToPage(
            PageConstants::MAIN,
            ChatSignalConstants::USER_PRESENCE_UPDATE,
            UserPresenceSignalData::fromFrontendChanges(UserFrontendStateProjector::updatesForUser($user)),
        );
        yield from $this->deliverToPage(
            PageConstants::ADMIN_USERS,
            ChatSignalConstants::USER_PRESENCE_UPDATE,
            UserPresenceSignalData::fromFrontendChanges(
                UserFrontendStateProjector::updatesForUser($user, includeConnectionStats: true),
            ),
        );
        yield from $this->deliverToPage(
            HilosPageConstants::HILOS_USERS,
            ChatSignalConstants::USER_PRESENCE_UPDATE,
            UserPresenceSignalData::fromFrontendChanges(
                UserFrontendStateProjector::updatesForUser($user, includeConnectionStats: true),
            ),
        );
        yield from $this->deliverToPage(
            HilosPageConstants::HILOS_USER,
            ChatSignalConstants::USER_PRESENCE_UPDATE,
            UserPresenceSignalData::fromFrontendChanges(
                UserFrontendStateProjector::updatesForUser($user, includeConnectionStats: true),
            ),
            HilosPageRouteParams::HILOS_USER_USER_ID,
            (string)$userId,
        );

        yield from $this->buildTableDeliveries(
            new TableSourceEventDTO(DbChatContext::users, $userId, TableMutationType::Update),
            [
                TableChatContext::adminUsers => PageConstants::ADMIN_USERS,
                TableChatContext::hilosUsers => HilosPageConstants::HILOS_USERS,
            ],
        );
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function buildBotAgentStatusDeliveries(SourceChange $change): iterable
    {
        $botId = (int)$change->sourceId;
        if ($botId <= 0) {
            return;
        }

        $status = (string)($change->row[StateBotAgentStatus::status] ?? '');
        if ($status === '' && Hilos::$rt !== null) {
            $status = Hilos::$rt->botAgentStatuses[$botId]?->status ?? '';
        }

        $wireSignalName = match ($status) {
            StateBotAgentStatus::STATUS_JOINED => ChatSignalConstants::BOT_JOINED,
            StateBotAgentStatus::STATUS_LEFT => ChatSignalConstants::BOT_LEFT,
            default => null,
        };
        if ($wireSignalName === null) {
            return;
        }

        $entities = new EntitiesChangesDTO();
        if (Hilos::$db !== null) {
            $bot = Hilos::$db->bots[$botId] ?? null;
            if ($bot !== null) {
                $entities = new EntitiesChangesDTO(updates: [
                    DbChatContext::bots => [$bot->toArray(toFrontend: true)],
                ]);
            }
        }

        yield from $this->deliverToAcceptKeys(
            $this->allLocalAcceptKeys(),
            $wireSignalName,
            new ChatEventSignalDTO(
                $entities,
                frontend: BotFrontendStateProjector::updatesForBotStatus($botId, $status),
            ),
        );
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function buildGuardianAgentStatusDeliveries(SourceChange $change): iterable
    {
        $agentId = (string)($change->row[StateGuardianAgentStatus::agentId] ?? $change->sourceId);
        $status = (string)($change->row[StateGuardianAgentStatus::status] ?? '');
        if ($status === '' && Hilos::$rt !== null) {
            $status = Hilos::$rt->guardianAgentStatuses[$agentId]?->status ?? '';
        }
        if ($agentId === '' || $status === '') {
            return;
        }

        yield from $this->deliverToAcceptKeys(
            $this->allLocalAcceptKeys(),
            HilosSignalConstants::GUARDIAN_AGENT_STATUS_UPDATE,
            new SignalData([
                'agentId' => $agentId,
                'status' => $status,
            ]),
        );
    }

    /**
     * @param array<string, string> $tablePageMap Table key => page key
     * @return iterable<FrontendDelivery>
     */
    private function buildTableDeliveries(TableSourceEventDTO $event, array $tablePageMap): iterable
    {
        if (Hilos::$table === null || Hilos::$sr === null) {
            return;
        }

        foreach (Hilos::$table->buildMutationSignalsForSourceEvent($event, array_keys($tablePageMap)) as $tableSignal) {
            $page = $tablePageMap[$tableSignal->tableKey] ?? null;
            if (!is_string($page) || $page === '') {
                continue;
            }

            yield from $this->deliverToPage(
                $page,
                ChatSignalConstants::TABLE_MUTATION,
                $tableSignal,
            );
        }
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    private function deliverToPage(
        string $page,
        string $wireSignalName,
        SignalDataInterface $payload,
        ?string $paramKey = null,
        ?string $paramValue = null,
    ): iterable {
        if (Hilos::$sr === null) {
            return;
        }

        yield from $this->deliverToAcceptKeys(
            Hilos::$sr->getAcceptKeysForPage($page, $paramKey, $paramValue),
            $wireSignalName,
            $payload,
        );
    }

    /**
     * @param list<string> $acceptKeys
     * @return iterable<FrontendDelivery>
     */
    private function deliverToAcceptKeys(
        array $acceptKeys,
        string $wireSignalName,
        SignalDataInterface $payload,
    ): iterable {
        foreach (array_values(array_unique($acceptKeys)) as $acceptKey) {
            if ($acceptKey === '') {
                continue;
            }

            yield new FrontendDelivery($wireSignalName, $payload, $acceptKey);
        }
    }

    /**
     * @return list<string>
     */
    private function allLocalAcceptKeys(): array
    {
        return Hilos::$sr?->getSubscribedAcceptKeys() ?? [];
    }

    private function isOutboundModerationChange(SourceChange $change): bool
    {
        foreach ([
            StateChatUserState::outboundModerationAcceptKey,
            StateChatUserState::outboundModerationRequestId,
            StateChatUserState::outboundModerationPhase,
            StateChatUserState::outboundModerationMessage,
            StateChatUserState::outboundModerationAttachmentDraftIdsJson,
            StateChatUserState::outboundModerationReason,
            StateChatUserState::outboundModerationUpdatedAt,
        ] as $field) {
            if (array_key_exists($field, $change->row)) {
                return true;
            }
        }

        return false;
    }

    private function outboundModerationAcceptKeyForChange(SourceChange $change): string
    {
        $acceptKey = (string)($change->row[StateChatUserState::outboundModerationAcceptKey] ?? '');
        if ($acceptKey !== '') {
            return $acceptKey;
        }

        if (Hilos::$rt === null) {
            return '';
        }

        return Hilos::$rt->userStates[$change->sourceId]?->outboundModerationAcceptKey ?? '';
    }

    private function attachmentDraftAcceptKeyForChange(SourceChange $change): string
    {
        $acceptKey = (string)($change->row[StateAttachmentDraft::acceptKey] ?? '');
        if ($acceptKey !== '') {
            return $acceptKey;
        }

        if (Hilos::$rt === null) {
            return '';
        }

        return Hilos::$rt->attachmentDrafts[$change->sourceId]?->acceptKey ?? '';
    }

    private function isAcceptKeyOnPage(string $acceptKey, string $page): bool
    {
        return $acceptKey !== ''
            && in_array($acceptKey, Hilos::$sr?->getAcceptKeysForPage($page) ?? [], true);
    }

    private function userIdFromConnectionChange(SourceChange $change): int
    {
        $userId = (int)($change->row[StateConnection::userId] ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        if (Hilos::$rt === null) {
            return 0;
        }

        return (int)(Hilos::$rt->connections[$change->sourceId]?->userId ?? 0);
    }

    private function settingSourceRowKey(SourceChange $change): string
    {
        $key = (string)($change->row['key'] ?? '');
        if ($key !== '') {
            return $key;
        }

        if (Hilos::$db === null) {
            return '';
        }

        $setting = Hilos::$db->settings[(int)$change->sourceId] ?? null;

        return $setting !== null ? (string)$setting->key : '';
    }

    private function eventIdFromAttachmentChange(SourceChange $change): int
    {
        $eventId = (int)($change->row['event_id'] ?? $change->row['eventId'] ?? 0);
        if ($eventId > 0) {
            return $eventId;
        }

        if (Hilos::$db === null) {
            return 0;
        }

        return (int)(Hilos::$db->eventAttachments[(int)$change->sourceId]?->eventId ?? 0);
    }

    private function frontendUpdatesForEventUser(string $eventType, ?int $userId): ?FrontendChangesDTO
    {
        if (
            $userId === null
            || !in_array($eventType, [
                ChatEventType::USER_REGISTERED->value,
                ChatEventType::USER_RENAMED->value,
                ChatEventType::USER_RENAMED_BY_ADMIN->value,
            ], true)
            || Hilos::$db === null
        ) {
            return null;
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            return null;
        }

        return UserFrontendStateProjector::updatesForUser($user, includePublicUser: true);
    }
}
