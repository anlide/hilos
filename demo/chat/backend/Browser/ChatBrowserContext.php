<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Hilos;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Throwable;

/**
 * Chat demo browser-facing context.
 *
 * Supplies the chat-specific computed browser fields — user presence and
 * self-connection state — over the framework source fan-out. Settings rows are
 * delivered by the framework self-snapshot path (HilosSettingsTable), not here.
 */
final class ChatBrowserContext extends BrowserContext
{
    /**
     * Computes chat browser fields named by page/table configs.
     *
     * @param string $browserKey Browser table key
     * @param string $field Computed field name from the mirrored browser config
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value, or null when unavailable
     */
    protected function computeBrowserField(
        string $browserKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): mixed {
        if (
            $field === HilosUserPresenceSummary::presence
            || $field === HilosUserPresenceSummary::onlineSessionCount
        ) {
            return $this->computeUserPresenceSummaryField($field, $rowKey);
        }

        if (
            $field === SelfConnectionSignalData::messageRateLimitSecondsRemaining
            || $field === SelfConnectionSignalData::outboundModerationState
            || $field === SelfConnectionSignalData::fileUploadState
            || $field === SelfConnectionSignalData::fileUploadProgress
        ) {
            return $this->computeSelfConnectionField($field, $acceptKey);
        }

        return parent::computeBrowserField(
            $browserKey,
            $field,
            $rowKey,
            $acceptKey,
            $pageParams,
            $browserParams,
            $sources,
        );
    }

    /**
     * Resolves the durable user id behind an accept key from the chat runtime
     * connection registry, where the handshake records the acceptKey -> user
     * mapping. Lets the ACCESS browser guard identify the subscriber; an
     * unregistered accept key (a guest before any handshake) resolves to null and
     * is denied.
     *
     * @param string $acceptKey Subscriber accept key
     * @return ?int Durable user id, or null when no connection is registered
     */
    protected function resolveCurrentUserId(string $acceptKey): ?int
    {
        try {
            return Hilos::$rt?->connections[$acceptKey]?->userId;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Computes runtime connection summary fields for user-shaped rows.
     *
     * @param string $field Summary field name
     * @param int|string $rowKey User id row key
     * @return mixed Summary field value, or null when runtime state is unavailable
     */
    private function computeUserPresenceSummaryField(string $field, int|string $rowKey): mixed
    {
        $userId = (int) $rowKey;
        if ($userId <= 0 || (string) $userId !== (string) $rowKey) {
            return null;
        }

        try {
            $summary = Hilos::$rt?->connections->summaryForUser($userId);
        } catch (Throwable) {
            return null;
        }

        return match ($field) {
            HilosUserPresenceSummary::presence => $summary?->presence,
            HilosUserPresenceSummary::onlineSessionCount => $summary?->onlineSessionCount,
            default => null,
        };
    }

    /**
     * Computes current-connection fields for one subscribed accept key.
     *
     * @param string $field Self-connection field name
     * @param string $acceptKey Subscriber accept key
     * @return mixed Self-connection field value, or null when the connection is gone
     */
    private function computeSelfConnectionField(string $field, string $acceptKey): mixed
    {
        try {
            $connection = Hilos::$rt?->connections[$acceptKey] ?? null;
        } catch (Throwable) {
            return null;
        }

        if ($connection === null) {
            return null;
        }

        $payload = SelfConnectionBrowserPayload::forConnection($connection);

        return $payload[$field] ?? null;
    }
}
