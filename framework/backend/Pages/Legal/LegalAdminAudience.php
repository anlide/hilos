<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptanceFiltersSignalData;
use Hilos\Legal\LegalTally;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;

/** Process-local histograms shared by the legal agent's pages and their table windows. */
final class LegalAdminAudience
{
    /** @var array<string, string> Page key per subscribing accept key */
    private static array $subscribers = [];

    /** @var ?array<string, LegalTally> SQL histogram projections, absent after a source change */
    private static ?array $cache = null;

    private static ?HilosLegalAcceptanceFiltersSignalData $filtersCache = null;
    private static ?string $filtersFingerprint = null;
    private static ?string $cacheDate = null;
    private static ?string $deliveredDate = null;
    private static bool $stale = false;

    /**
     * @return array<string, LegalTally> Shared projection map on the server's calendar date
     * @throws LegalException When the catalog is invalid
     * @throws DatabaseException When acceptance histograms cannot be read
     */
    public static function tallies(): array
    {
        $today = LegalStandingResolver::today();
        if (self::$cache === null) {
            self::$cache = LegalTally::all($today);
        } elseif (self::$cacheDate !== $today) {
            self::$cache = array_map(
                static fn (LegalTally $tally): LegalTally => LegalTally::of(
                    $tally->document,
                    $tally->heldByRevision,
                    $tally->acceptedByRevision,
                    $today,
                ),
                self::$cache,
            );
        }
        self::$cacheDate = $today;

        return self::$cache;
    }

    /**
     * @return HilosLegalAcceptanceFiltersSignalData Shared vocabulary of declared documents and recorded revisions
     * @throws DatabaseException When recorded revision keys cannot be read
     */
    public static function filters(): HilosLegalAcceptanceFiltersSignalData
    {
        if (self::$filtersCache !== null) {
            return self::$filtersCache;
        }

        $recorded = Hilos::$db->legalAcceptances->revisionsOnRecord();
        $declared = [];
        $catalogAvailable = true;
        try {
            foreach (LegalCatalogResolver::documents() as $document) {
                $declared[$document->value] = array_reverse(array_column(LegalCatalogResolver::revisions($document), 'id'));
            }
        } catch (LegalException) {
            $declared = [];
            $catalogAvailable = false;
        }

        ksort($recorded, SORT_STRING);
        $documents = [];
        foreach (array_unique([...array_keys($declared), ...array_keys($recorded)]) as $document) {
            $ids = $recorded[$document] ?? [];
            rsort($ids, SORT_STRING);
            $declaredIds = $declared[$document] ?? [];
            $revisions = [];
            foreach ([...array_intersect($declaredIds, $ids), ...array_diff($ids, $declaredIds)] as $id) {
                $revisions[] = [
                    HilosLegalAcceptanceFiltersSignalData::revisionId => $id,
                    HilosLegalAcceptanceFiltersSignalData::declared => $catalogAvailable ? in_array($id, $declaredIds, true) : null,
                ];
            }
            $documents[] = [
                HilosLegalAcceptanceFiltersSignalData::document => (string) $document,
                HilosLegalAcceptanceFiltersSignalData::declared => $catalogAvailable ? isset($declared[$document]) : null,
                HilosLegalAcceptanceFiltersSignalData::revisions => $revisions,
            ];
        }

        return self::$filtersCache = new HilosLegalAcceptanceFiltersSignalData($documents);
    }

    /** Invalidates SQL histograms and filter vocabulary; source events are folded into one read on demand. */
    public static function markStale(): void
    {
        self::$cache = null;
        self::$filtersCache = null;
        self::$stale = true;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param string $page Served legal page
     */
    public static function addSubscriber(string $acceptKey, string $page): void
    {
        self::$subscribers[$acceptKey] = $page;
    }

    /** @param string $acceptKey Connection leaving the section or closing */
    public static function removeSubscriber(string $acceptKey): void
    {
        unset(self::$subscribers[$acceptKey]);
    }

    /** Clears the process-local audience when the serving agent stops. */
    public static function reset(): void
    {
        self::$subscribers = [];
        self::$cache = null;
        self::$filtersCache = null;
        self::$filtersFingerprint = null;
        self::$cacheDate = null;
        self::$deliveredDate = null;
        self::$stale = false;
    }

    /**
     * Runs only in the section's dedicated monopolistic process: SQL may outlast a normal tick.
     *
     * @param PageAgentInterface $agent Agent serving the section; windows use the browser router
     * @throws DatabaseException When acceptance histograms cannot be read
     * @throws InvalidArgumentException When a table-window signal cannot be named
     */
    public static function onAgentTick(PageAgentInterface $agent): void
    {
        if (self::$subscribers === []) {
            return;
        }
        $today = LegalStandingResolver::today();
        $refreshTallies = self::$stale || self::$deliveredDate !== $today;
        $hasTallies = array_intersect(self::$subscribers, [
            HilosPageConstants::HILOS_LEGAL,
            HilosPageConstants::HILOS_LEGAL_DOCUMENT,
            HilosPageConstants::HILOS_LEGAL_REVISION,
        ]) !== [];
        if ($refreshTallies && $hasTallies) {
            try {
                self::tallies();
            } catch (LegalException) {
                // The page response carries the refusal; its catalog tables answer empty windows.
            }
            foreach (self::$subscribers as $acceptKey => $page) {
                $tables = match ($page) {
                    HilosPageConstants::HILOS_LEGAL => [HilosLegalDocumentsTable::TABLE, HilosLegalChecksTable::TABLE],
                    HilosPageConstants::HILOS_LEGAL_DOCUMENT,
                    HilosPageConstants::HILOS_LEGAL_REVISION => [HilosLegalRevisionsTable::TABLE],
                    default => [],
                };
                foreach ($tables as $table) {
                    $viewport = Hilos::$sr?->getTableViewport($acceptKey, $table);
                    if ($viewport !== null) {
                        Hilos::$browser?->sendTableWindow($page, $acceptKey, $viewport);
                    }
                }
            }
        }
        if (in_array(HilosPageConstants::HILOS_LEGAL_ACCEPTANCES, self::$subscribers, true)
            && self::$stale) {
            $data = self::filters();
            $fingerprint = serialize($data->toArray());
            if ($fingerprint !== self::$filtersFingerprint) {
                foreach (self::$subscribers as $acceptKey => $page) {
                    if ($page !== HilosPageConstants::HILOS_LEGAL_ACCEPTANCES) {
                        continue;
                    }
                    $pageClass = Hilos::appClass()::PAGES[$page] ?? null;
                    if ($pageClass === null) {
                        continue;
                    }
                    try {
                        PageAccessGate::assert($pageClass, $acceptKey);
                        Hilos::$browser?->assertSubscriptionAccess($page, $acceptKey, new PageRouteParams([]));
                    } catch (PageSubscriptionException) {
                        // A reassessment keeps the subscription alive while its current verdict denies delivery.
                        continue;
                    }
                    Hilos::$sr->queueSignal(
                        signalSource: $agent->getAgentSignalSource(),
                        signalType: new SignalType(SignalTypeConstants::WS_USER),
                        signalName: new SignalName(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES),
                        signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
                    );
                }
                self::$filtersFingerprint = $fingerprint;
            }
        }
        self::$stale = false;
        self::$deliveredDate = $today;
    }
}
