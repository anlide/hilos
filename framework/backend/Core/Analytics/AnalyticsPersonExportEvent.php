<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use DateTimeZone;
use Hilos\DataExport\DataExportTime;

/** One authenticated analytics fact selected for a person's data copy. */
final readonly class AnalyticsPersonExportEvent
{
    /**
     * @param int $id Raw event number, used only for keyset pagination
     * @param int $createdTs Source moment in milliseconds
     * @param string $kind Authenticated event kind
     * @param ?string $name Action name, or null
     * @param ?string $page Page name, or null
     * @param ?array<string, mixed> $params Page route parameters, or null
     * @param ?string $address Client network address, or null
     * @param ?int $sessionId Application session number, or null
     * @param ?int $browserSessionId Analytics browser session number, or null
     * @param ?int $subjectUserId Account under takeover, or null
     */
    public function __construct(
        public int $id,
        public int $createdTs,
        public string $kind,
        public ?string $name,
        public ?string $page,
        public ?array $params,
        public ?string $address,
        public ?int $sessionId,
        public ?int $browserSessionId,
        public ?int $subjectUserId,
    ) {
    }

    /**
     * @param DateTimeZone $timeZone Zone of the archive being written
     * @return array<string, int|string|array<string, mixed>|null> Portable row without transport secrets
     */
    public function archiveRow(DateTimeZone $timeZone): array
    {
        return [
            'at' => DataExportTime::isoMs($this->createdTs, $timeZone),
            'kind' => $this->kind,
            'name' => $this->name,
            'page' => $this->page,
            'params' => $this->params,
            'address' => $this->address,
            'sessionId' => $this->sessionId,
            'browserSessionId' => $this->browserSessionId,
            'subjectUserId' => $this->subjectUserId,
        ];
    }
}
