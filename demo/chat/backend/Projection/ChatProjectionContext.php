<?php

declare(strict_types=1);

namespace Demo\Chat\Projection;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Frontend\BotFrontendStateProjector;
use Demo\Chat\Projection\Page\AdminBotsPageProjection;
use Demo\Chat\Projection\Page\AdminModeratorPageProjection;
use Demo\Chat\Projection\Page\AdminUsersPageProjection;
use Demo\Chat\Projection\Page\HilosSettingsPageProjection;
use Demo\Chat\Projection\Page\HilosUserPageProjection;
use Demo\Chat\Projection\Page\HilosUsersPageProjection;
use Demo\Chat\Projection\Page\MainPageProjection;
use Demo\Chat\Projection\Util\ChatEventBroadcastBuilder;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\State\Item\GuardianAgentStatus as StateGuardianAgentStatus;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Projection\ProjectionContext;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SourceChangeSet;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Hilos;

/**
 * Chat demo projection context.
 *
 * Registers seven {@see \Hilos\Core\Projection\PageProjection} instances that
 * cover all browser pages of the demo, and adds project-level global broadcast
 * rules for chat events and agent presence that fan out to every locally
 * subscribed accept key (regardless of which page they are on).
 */
final class ChatProjectionContext extends ProjectionContext
{
    public function configure(): void
    {
        $this->register(new MainPageProjection());
        $this->register(new AdminBotsPageProjection());
        $this->register(new AdminUsersPageProjection());
        $this->register(new AdminModeratorPageProjection());
        $this->register(new HilosUsersPageProjection());
        $this->register(new HilosUserPageProjection());
        $this->register(new HilosSettingsPageProjection());
    }

    public function flushToSignalRouter(): void
    {
        if (!$this->hasChanges() || Hilos::$sr === null) {
            return;
        }

        $changes = $this->drainChanges();

        $this->dispatchGlobalBroadcasts($changes);
        $this->dispatchChangesToPagesAndGroups($changes);
    }

    /**
     * Broadcasts that fan out to every locally subscribed accept key on this
     * worker, regardless of which page the client is currently on. Preserves
     * the historical "everyone receives chat events and agent presence" behavior.
     */
    private function dispatchGlobalBroadcasts(SourceChangeSet $changes): void
    {
        if (Hilos::$sr === null) {
            return;
        }
        $audience = Hilos::$sr->getSubscribedAcceptKeys();
        if ($audience === []) {
            return;
        }

        $deliveredEventIds = $this->eventIdsBroadcastDirectly($changes);

        foreach ($changes->all() as $change) {
            $this->queueDeliveries($this->buildGlobalDeliveriesForChange($change, $audience, $deliveredEventIds));
        }
    }

    /**
     * @param list<string> $audience
     * @param array<int, true> $deliveredEventIds
     * @return iterable<ProjectionDelivery>
     */
    private function buildGlobalDeliveriesForChange(
        SourceChange $change,
        array $audience,
        array $deliveredEventIds,
    ): iterable {
        if ($change->kind === SourceChange::KIND_DB) {
            if ($change->sourceKey === DbChatContext::events) {
                if ($change->mutationType !== TableMutationType::Create) {
                    return;
                }
                yield from ChatEventBroadcastBuilder::forEventId((int)$change->sourceId, $audience);
                return;
            }

            if ($change->sourceKey === DbChatContext::eventAttachments) {
                if ($change->mutationType === TableMutationType::Delete) {
                    return;
                }
                $eventId = ChatEventBroadcastBuilder::eventIdFromAttachmentChange($change);
                if ($eventId <= 0 || isset($deliveredEventIds[$eventId])) {
                    return;
                }
                yield from ChatEventBroadcastBuilder::forEventId($eventId, $audience);
                return;
            }

            if ($change->sourceKey === DbChatContext::bots) {
                yield from $this->buildBotUpdatedDeliveries($change, $audience);
                return;
            }
        }

        if ($change->kind === SourceChange::KIND_RT) {
            if ($change->sourceKey === RtChatContext::botAgentStatuses) {
                yield from $this->buildBotAgentStatusDeliveries($change, $audience);
                return;
            }

            if ($change->sourceKey === RtChatContext::guardianAgentStatuses) {
                yield from $this->buildGuardianAgentStatusDeliveries($change, $audience);
            }
        }
    }

    /**
     * @return array<int, true>
     */
    private function eventIdsBroadcastDirectly(SourceChangeSet $changes): array
    {
        $ids = [];
        foreach ($changes->all() as $change) {
            if (
                $change->kind === SourceChange::KIND_DB
                && $change->sourceKey === DbChatContext::events
                && $change->mutationType === TableMutationType::Create
            ) {
                $ids[(int)$change->sourceId] = true;
            }
        }
        return $ids;
    }

    /**
     * @param list<string> $audience
     * @return iterable<ProjectionDelivery>
     */
    private function buildBotUpdatedDeliveries(SourceChange $change, array $audience): iterable
    {
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

        $payload = new ChatEventSignalDTO(
            entities: new EntitiesChangesDTO(updates: [
                DbChatContext::bots => [$bot->toArray(toFrontend: true)],
            ]),
        );

        foreach (array_values(array_unique($audience)) as $acceptKey) {
            if ($acceptKey === '') {
                continue;
            }
            yield new ProjectionDelivery(ChatSignalConstants::BOT_UPDATED, $payload, $acceptKey);
        }
    }

    /**
     * @param list<string> $audience
     * @return iterable<ProjectionDelivery>
     */
    private function buildBotAgentStatusDeliveries(SourceChange $change, array $audience): iterable
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

        $payload = new ChatEventSignalDTO(
            entities: $entities,
            frontend: BotFrontendStateProjector::updatesForBotStatus($botId, $status),
        );

        foreach (array_values(array_unique($audience)) as $acceptKey) {
            if ($acceptKey === '') {
                continue;
            }
            yield new ProjectionDelivery($wireSignalName, $payload, $acceptKey);
        }
    }

    /**
     * @param list<string> $audience
     * @return iterable<ProjectionDelivery>
     */
    private function buildGuardianAgentStatusDeliveries(SourceChange $change, array $audience): iterable
    {
        $agentId = (string)($change->row[StateGuardianAgentStatus::agentId] ?? $change->sourceId);
        $status = (string)($change->row[StateGuardianAgentStatus::status] ?? '');
        if ($status === '' && Hilos::$rt !== null) {
            $status = Hilos::$rt->guardianAgentStatuses[$agentId]?->status ?? '';
        }
        if ($agentId === '' || $status === '') {
            return;
        }

        $payload = new SignalData([
            'agentId' => $agentId,
            'status' => $status,
        ]);

        foreach (array_values(array_unique($audience)) as $acceptKey) {
            if ($acceptKey === '') {
                continue;
            }
            yield new ProjectionDelivery(HilosSignalConstants::GUARDIAN_AGENT_STATUS_UPDATE, $payload, $acceptKey);
        }
    }
}
