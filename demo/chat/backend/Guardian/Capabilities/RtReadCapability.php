<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Capabilities;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Guardian\Capabilities\AbstractGuardianCapability;
use Hilos\Guardian\DTO\CapabilityResult;

final class RtReadCapability extends AbstractGuardianCapability
{
    public function getName(): string
    {
        return 'rt.read';
    }

    public function execute(array $payload = [], array $context = []): CapabilityResult
    {
        $limit = (int) ($payload['limitPerCollection'] ?? 100);
        $limit = max(1, min($limit, 300));

        $collectionNames = [
            RtChatContext::connections,
            RtChatContext::moderationStates,
            RtChatContext::chatContexts,
        ];

        $snapshot = [];
        foreach ($collectionNames as $collectionName) {
            $rows = [];
            foreach (Hilos::$rt->{$collectionName} as $item) {
                $row = $item->toArray();
                // Hide acceptKey by default to reduce accidental sensitive leakage.
                if (isset($row['acceptKey'])) {
                    $row['acceptKey'] = '[masked]';
                }
                $rows[] = $row;
                if (count($rows) >= $limit) {
                    break;
                }
            }

            $snapshot[$collectionName] = [
                'count' => count(Hilos::$rt->{$collectionName}),
                'rows' => $rows,
            ];
        }

        return new CapabilityResult(ok: true, data: ['rt' => $snapshot]);
    }
}
