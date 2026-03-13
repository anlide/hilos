<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Capabilities;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Hilos;
use Hilos\Guardian\Capabilities\AbstractGuardianCapability;
use Hilos\Guardian\DTO\CapabilityResult;

/**
 * DbReadCapability - Guardian capability for reading database snapshot.
 *
 * Returns collection counts and row samples for users, events, bots, moderator prompt pieces.
 */
final class DbReadCapability extends AbstractGuardianCapability
{
    /**
     * Get capability name.
     *
     * @return string Capability name
     */
    public function getName(): string
    {
        return 'db.read';
    }

    /**
     * Execute capability: read database collections snapshot.
     *
     * @param array $payload Payload (limitPerCollection, default 50)
     * @param array $context Execution context (unused)
     * @return CapabilityResult Snapshot with counts and row samples
     */
    public function execute(array $payload = [], array $context = []): CapabilityResult
    {
        $limit = (int) ($payload['limitPerCollection'] ?? 50);
        $limit = max(1, min($limit, 200));

        $collectionNames = [
            DbChatContext::users,
            DbChatContext::events,
            DbChatContext::bots,
            DbChatContext::moderatorPromptPieces,
        ];

        $snapshot = [];
        foreach ($collectionNames as $collectionName) {
            $rows = [];
            foreach (Hilos::$db->{$collectionName} as $item) {
                $rows[] = $item->toArray();
                if (count($rows) >= $limit) {
                    break;
                }
            }

            $snapshot[$collectionName] = [
                'count' => count(Hilos::$db->{$collectionName}),
                'rows' => $rows,
            ];
        }

        return new CapabilityResult(ok: true, data: ['db' => $snapshot]);
    }
}
