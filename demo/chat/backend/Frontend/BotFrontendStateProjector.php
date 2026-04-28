<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

use Demo\Chat\Database\View\Item\Bot as DbBot;
use Demo\Chat\Frontend\DTO\FrontendBotPresenceProjection;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Hilos\Core\Router\DTO\FrontendChangesDTO;

/**
 * Builds bot-related frontend state projections.
 */
final class BotFrontendStateProjector
{
    private const string PRESENCE_ONLINE = 'online';
    private const string PRESENCE_OFFLINE = 'offline';

    /**
     * Builds a full bot presence snapshot for a bot collection.
     *
     * @param iterable<DbBot> $bots Bots to project
     * @return FrontendChangesDTO Full frontend state snapshot
     */
    public static function fullForBots(iterable $bots): FrontendChangesDTO
    {
        return new FrontendChangesDTO(full: self::collectionsForBots($bots));
    }

    /**
     * Appends a full bot presence snapshot to an existing frontend state snapshot.
     *
     * @param FrontendChangesDTO $frontend Existing frontend state snapshot
     * @param iterable<DbBot> $bots Bots to project
     * @return FrontendChangesDTO Combined frontend state snapshot
     */
    public static function appendFullForBots(FrontendChangesDTO $frontend, iterable $bots): FrontendChangesDTO
    {
        $payload = $frontend->toArray();
        foreach (self::collectionsForBots($bots) as $collectionKey => $items) {
            foreach ($items as $item) {
                $payload['full'][$collectionKey][] = $item;
            }
        }

        return FrontendChangesDTO::fromArray($payload);
    }

    /**
     * Builds an incremental bot presence update from a lifecycle marker.
     *
     * @param int $botId Bot database id
     * @param string $status Bot runtime lifecycle marker
     * @return FrontendChangesDTO Incremental frontend state update
     */
    public static function updatesForBotStatus(int $botId, string $status): FrontendChangesDTO
    {
        return new FrontendChangesDTO(updates: [
            FrontendStateCollectionKey::BOT_PRESENCE => [
                new FrontendBotPresenceProjection(
                    botId: $botId,
                    presence: self::presenceFromStatus($status),
                )->toArray(),
            ],
        ]);
    }

    /**
     * Builds projection collections for the given bots.
     *
     * @param iterable<DbBot> $bots Bots to project
     * @return array<string, list<array<string, mixed>>>
     */
    private static function collectionsForBots(iterable $bots): array
    {
        $collections = [];
        foreach ($bots as $bot) {
            $botId = (int) $bot->id;
            $status = Hilos::$rt->botAgentStatuses[$botId]?->status ?? StateBotAgentStatus::STATUS_LEFT;
            $collections[FrontendStateCollectionKey::BOT_PRESENCE][] = new FrontendBotPresenceProjection(
                botId: $botId,
                presence: self::presenceFromStatus($status),
            )->toArray();
        }

        return $collections;
    }

    private static function presenceFromStatus(string $status): string
    {
        return $status === StateBotAgentStatus::STATUS_JOINED
            ? self::PRESENCE_ONLINE
            : self::PRESENCE_OFFLINE;
    }
}
