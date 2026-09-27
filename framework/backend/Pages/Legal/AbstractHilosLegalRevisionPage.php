<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

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
use Hilos\Pages\Legal\DTO\HilosLegalRevisionPageSubscribeParams;

/**
 * ADMIN subscription carrying the requested legal declaration in the first page response.
 */
abstract class AbstractHilosLegalRevisionPage extends AbstractHilosLegalPage
{
    public const string PAGE = HilosPageConstants::HILOS_LEGAL_REVISION;
    public const string SECTION = 'legalRevision';
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_REVISION,
    ];

    /**
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When a required key is missing
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        HilosLegalRevisionPageSubscribeParams::fromPageRouteParams($params);
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
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Raw route parameters
     * @return ?PagePayload Declaration or catalog refusal
     * @throws HilosException When route keys are absent or unknown, or acceptance reads fail
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $route = HilosLegalRevisionPageSubscribeParams::fromPageRouteParams($params);
        try {
            $documents = LegalCatalogResolver::documents();
            $kind = LegalDocument::tryFrom($route->documentKey);
            $revision = null;
            if ($kind !== null && in_array($kind, $documents, true)) {
                foreach (LegalCatalogResolver::revisions($kind) as $candidate) {
                    if ($candidate->id === $route->revisionId) {
                        $revision = $candidate;
                        break;
                    }
                }
            }
            if ($revision === null) {
                if ((Hilos::$db->legalAcceptances->acceptedCounts($route->documentKey)[$route->revisionId] ?? 0) === 0) {
                    throw new PageResourceNotFoundException('No such legal revision');
                }

                return new PagePayload(data: [
                    self::CATALOG_REFUSAL => null,
                    self::SECTION => [
                        'document' => $route->documentKey,
                        'revisionId' => $route->revisionId,
                        'declared' => false,
                        'revision' => null,
                        'current' => null,
                        'predecessorId' => null,
                        'clauses' => null,
                        'changes' => null,
                    ],
                ]);
            }
            $predecessor = LegalCatalogResolver::predecessor($kind, $revision->id);

            return new PagePayload(data: [
                self::CATALOG_REFUSAL => null,
                self::SECTION => [
                    'document' => $route->documentKey,
                    'revisionId' => $revision->id,
                    'declared' => true,
                    'revision' => LegalWire::revision($revision),
                    'current' => LegalCatalogResolver::latestRevision($kind)->id === $revision->id,
                    'predecessorId' => $predecessor?->id,
                    'clauses' => LegalWire::clauses(LegalCatalogResolver::compose($kind, $revision->id)),
                    'changes' => $predecessor === null ? null
                        : LegalWire::changes(LegalRevisionComparison::between($kind, $predecessor->id, $revision->id)),
                ],
            ]);
        } catch (LegalException $e) {
            return new PagePayload(data: [self::CATALOG_REFUSAL => $e->getMessage(), self::SECTION => null]);
        }
    }
}
