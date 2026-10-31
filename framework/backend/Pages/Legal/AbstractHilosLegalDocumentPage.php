<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevisionComparison;
use Hilos\Legal\LegalWire;
use Hilos\Legal\StandardSetCatalog;
use Hilos\Pages\Legal\DTO\HilosLegalDocumentPageSubscribeParams;
use Hilos\Tables\Legal\HilosLegalDocumentSetTable;
use Hilos\Tables\Legal\HilosLegalDocumentDeviationsTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;

/**
 * ADMIN subscription carrying the requested legal declaration in the first page response.
 */
abstract class AbstractHilosLegalDocumentPage extends AbstractHilosLegalPage
{
    public const string PAGE = HilosPageConstants::HILOS_LEGAL_DOCUMENT;
    public const string SECTION = 'legalDocument';
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_DOCUMENT,
    ];

    /**
     * Keeps reported list windows only within this document's route scope.
     *
     * @param string $acceptKey Subscribing connection whose reported windows are checked
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When a required key is missing
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $route = HilosLegalDocumentPageSubscribeParams::fromPageRouteParams($params);
        if (Hilos::$sr === null) {
            return;
        }

        $reported = Hilos::$sr->takeReportedTableWindows($acceptKey);
        foreach ([HilosLegalDocumentSetTable::TABLE, HilosLegalDocumentDeviationsTable::TABLE, HilosLegalRevisionsTable::TABLE] as $tableKey) {
            $window = $reported[$tableKey] ?? null;
            if ($window !== null && (
                ($window->filter[HilosLegalRevisionsTable::FILTER_DOCUMENT] ?? null) !== $route->documentKey
                || ($window->filter[HilosLegalRevisionsTable::FILTER_REVISION] ?? null) !== null
            )) {
                unset($reported[$tableKey]);
            }
        }
        Hilos::$sr->reportTableWindows($acceptKey, $reported);
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Updated route parameters
     * @throws HilosException When the subscription cannot be answered
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscribing connection; only an admin is sent the text of a catalog refusal
     * @param PageRouteParams $params Raw route parameters
     * @return ?PagePayload Declaration or catalog refusal
     * @throws HilosException When route keys are absent, the document has neither declaration nor records, or acceptance reads fail
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $route = HilosLegalDocumentPageSubscribeParams::fromPageRouteParams($params);
        try {
            $documents = LegalCatalogResolver::documents();
            $kind = LegalDocument::tryFrom($route->documentKey);
            $declared = $kind !== null && in_array($kind, $documents, true);
            if (!$declared && !in_array($route->documentKey, Hilos::$db->legalAcceptances->documentsOnRecord(), true)) {
                throw new PageResourceNotFoundException('No such legal document');
            }
            $revision = $declared ? LegalCatalogResolver::latestRevision($kind) : null;
            $set = $revision === null ? null : StandardSetCatalog::set($kind, $revision->setVersion);
            $latest = $declared ? StandardSetCatalog::latest($kind) : null;
            $tally = LegalAdminAudience::tallies()[$route->documentKey] ?? null;

            return new PagePayload(data: [
                self::CATALOG_REFUSAL => null,
                self::SECTION => [
                    'document' => $route->documentKey,
                    'declared' => $declared,
                    'revision' => $revision === null ? null : LegalWire::revision($revision),
                    'set' => $set === null ? null : LegalWire::standardSet($set),
                    'newerSet' => $latest === null || $latest->version <= $set->version ? null : [
                        'version' => $latest->version,
                        'publishedOn' => $latest->publishedOn,
                        'significance' => $latest->significance->value,
                        'changes' => LegalWire::changes(LegalRevisionComparison::standardSets($kind, $set->version, $latest->version)),
                    ],
                    'deviations' => $revision === null ? [] : LegalWire::deviations($revision),
                    'covered' => $tally?->covered ?? 0,
                    'window' => $tally?->window ?? 0,
                    'lapsed' => $tally?->lapsed ?? 0,
                    'standardSetCatalogClass' => StandardSetCatalog::class,
                    'projectCatalogClass' => Hilos::legalCatalogClass(),
                ],
            ]);
        } catch (LegalException $e) {
            return new PagePayload(data: [self::CATALOG_REFUSAL => $this->failureText($acceptKey, $e), self::SECTION => null]);
        }
    }

    /**
     * Declares where each field of the page payload comes from, for a viewer of the admin view mode (HIL-1250).
     *
     * The section carries catalog declarations and their sources, aggregate counts and the route parameter echo.
     *
     * @return array<string, WireField>
     */
    protected function dataFields(): array
    {
        return [
            ...parent::dataFields(),
            self::SECTION => WireField::notPersonal(),
        ];
    }
}
