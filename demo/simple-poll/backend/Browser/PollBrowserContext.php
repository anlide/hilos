<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Browser;

use Demo\SimplePoll\Hilos;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Throwable;

/**
 * PollBrowserContext - Browser-facing context ($browser layer) for simple-poll.
 *
 * Supplies the one computed browser field the demo needs: a user row's runtime
 * presence summary (presence + online session count), read from the RT
 * connections presence source over the framework source fan-out. The users table
 * and the single-user detail table both declare these as COMPUTED fields, so
 * without this override a connected user would render as offline / 0 sessions.
 * Settings rows are delivered by the framework self-snapshot path
 * (HilosSettingsTable), not here.
 */
final class PollBrowserContext extends BrowserContext
{
    /**
     * Computes the demo's browser fields named by page/table configs.
     *
     * @param string $tableKey Browser table key
     * @param string $field Computed field name from the mirrored browser config
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $tableParams Resolved table params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value, or null when unavailable
     */
    protected function computeBrowserField(
        string $tableKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
        array $sources,
    ): mixed {
        if (
            $field === HilosUserPresenceSummary::presence
            || $field === HilosUserPresenceSummary::onlineSessionCount
        ) {
            return $this->computeUserPresenceSummaryField($field, $rowKey);
        }

        return parent::computeBrowserField(
            $tableKey,
            $field,
            $rowKey,
            $acceptKey,
            $pageParams,
            $tableParams,
            $sources,
        );
    }

    /**
     * Computes a runtime connection presence summary field for a user row.
     *
     * @param string $field Summary field name (presence or online session count)
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
}
