<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** Browser-session summary with only the fields needed by the admin section. */
final readonly class AnalyticsSectionBrowserSession
{
    /**
     * @param int $id Analytics browser-session number
     * @param int $firstSeenTs First recorded moment in milliseconds
     * @param int $lastSeenTs Last recorded moment in milliseconds
     * @param ?string $browserDescription Short current user-agent description
     * @param ?int $lastSignedInUserId Last signed-in account, or null for a guest
     * @param ?string $lastSignedInUserLabel Current account name or a deleted-account label
     * @param int $actionCount Actions on connections already attached to this session
     */
    public function __construct(
        public int $id,
        public int $firstSeenTs,
        public int $lastSeenTs,
        public ?string $browserDescription,
        public ?int $lastSignedInUserId,
        public ?string $lastSignedInUserLabel,
        public int $actionCount,
    ) {
    }
}
