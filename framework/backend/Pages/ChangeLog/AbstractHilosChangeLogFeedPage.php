<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\View\Collection\ChangeLogEntries;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogEntryReadActionDTO;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogEntryReadReplyDTO;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogReceiptReadActionDTO;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogReceiptReadReplyDTO;
use Hilos\Tables\ChangeLog\HilosChangeLogTableParts;

/**
 * Change Log feed page served by the hilos_change_log agent.
 * Its subscription answers with a snapshot of the journal overview.
 */
abstract class AbstractHilosChangeLogFeedPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG_FEED;

    public const PageReach REACH = PageReach::ROUTE;

    public const string OVERVIEW = 'changeLogOverview';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_FEED,
    ];

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ => HilosChangeLogReceiptReadActionDTO::class,
        HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ => HilosChangeLogEntryReadActionDTO::class,
    ];

    public const array READING_ACTIONS = [
        HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ,
        HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ,
    ];

    private const int CONTINUATION_PROBE_ROWS = 1;

    /**
     * @param string $acceptKey Requesting connection
     * @param string $action Requested journal read
     * @param ActionPayloadDTO $dto Parsed detail address
     * @return ?ActionReplyDTO Receipt portion or full bare entry
     * @throws AgentUnknownActionException When the action is not supported
     * @throws InvalidActionPayloadException When the DTO does not match the action
     * @throws ValidationException When the detail or receipt cursor is unavailable
     * @throws LogicException When the browser context cannot judge the requesting viewer
     * @throws HilosException When the journal or current person names cannot be read
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ:
                if (!$dto instanceof HilosChangeLogReceiptReadActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosChangeLogReceiptReadActionDTO::class, $dto);
                }
                return $this->receiptRead($acceptKey, $dto);

            case HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ:
                if (!$dto instanceof HilosChangeLogEntryReadActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosChangeLogEntryReadActionDTO::class, $dto);
                }
                return $this->entryRead($acceptKey, $dto);

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @param string $acceptKey Subscribing connection, unused for this shared snapshot
     * @param PageRouteParams $params Route parameters, unused
     * @return PagePayload Journal overview for the opening response
     * @throws HilosException When the journal or live schema cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $overview = Hilos::$db->changeLogTables->sectionOverview();
        return new PagePayload(data: [self::OVERVIEW => [
            'journalEntries' => $overview->journalEntries,
            'journalBytes' => $overview->journalBytes,
            'oldestAt' => $overview->oldestAt === null
                ? null : HilosChangeLogTableParts::displayTime($overview->oldestAt),
            'journaledTables' => $overview->journaledTables,
            'liveTables' => $overview->liveTables,
        ]]);
    }

    /** @return array<string, WireField> The journal counts, time, and table names are not personal */
    protected function dataFields(): array
    {
        return [self::OVERVIEW => WireField::notPersonal()];
    }

    /**
     * @param string $acceptKey Requesting connection
     * @param HilosChangeLogReceiptReadActionDTO $dto Receipt and optional preceding entry
     * @return HilosChangeLogReceiptReadReplyDTO Fresh header and bounded full entries
     * @throws ValidationException When the receipt is missing or empty, or the cursor belongs elsewhere
     * @throws LogicException When the browser context is not initialized
     * @throws HilosException When journal or person SQL fails
     */
    private function receiptRead(string $acceptKey, HilosChangeLogReceiptReadActionDTO $dto): HilosChangeLogReceiptReadReplyDTO
    {
        if (Hilos::$browser === null) {
            throw new LogicException('Change log reading requires a browser context');
        }
        $receipt = Hilos::$db->changeLogReceipts->sectionReceipt($dto->receiptId);
        if ($receipt === null) {
            throw new ValidationException('Receipt not found');
        }
        if ($dto->afterEntryId !== null
            && Hilos::$db->changeLogEntries->sectionEntry($dto->afterEntryId)?->receiptId !== $dto->receiptId) {
            throw new ValidationException('Invalid receipt cursor');
        }
        $entries = Hilos::$db->changeLogEntries->receiptEntries($dto->receiptId, $dto->afterEntryId, ChangeLogEntries::MAX_WINDOW_ROWS);
        $lastId = $entries->last()?->id;
        $next = $lastId !== null
            && !Hilos::$db->changeLogEntries->receiptEntries($dto->receiptId, $lastId, self::CONTINUATION_PROBE_ROWS)->isEmpty()
            ? $lastId : null;
        $header = HilosChangeLogDetailWire::receipt($receipt);
        if (Hilos::$browser->isAdminViewModeViewer(static::class, $acceptKey)) {
            $header = Hilos::$browser->hideForViewer($header, HilosChangeLogDetailWire::receiptFields());
        }
        $wireEntries = [];
        foreach ($entries as $entry) {
            $wireEntries[] = HilosChangeLogDetailWire::entry($entry);
        }
        return new HilosChangeLogReceiptReadReplyDTO($header, $wireEntries, $next);
    }

    /**
     * @param string $acceptKey Requesting connection
     * @param HilosChangeLogEntryReadActionDTO $dto Bare-entry address
     * @return HilosChangeLogEntryReadReplyDTO Full policy-filtered journal values
     * @throws ValidationException When the entry is missing or belongs to a receipt
     * @throws LogicException When the browser context is not initialized
     * @throws HilosException When journal SQL fails
     */
    private function entryRead(string $acceptKey, HilosChangeLogEntryReadActionDTO $dto): HilosChangeLogEntryReadReplyDTO
    {
        if (Hilos::$browser === null) {
            throw new LogicException('Change log reading requires a browser context');
        }
        $entry = Hilos::$db->changeLogEntries->sectionEntry($dto->entryId);
        if ($entry === null) {
            throw new ValidationException('Change not found');
        }
        if ($entry->receiptId !== null) {
            throw new ValidationException('This change belongs to a receipt');
        }
        return new HilosChangeLogEntryReadReplyDTO(HilosChangeLogDetailWire::entry($entry));
    }
}
