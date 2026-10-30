<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalCatalogResolver;

/** Read-only ADMIN catalog subscription; aggregate rows come from the bound Documents table. */
abstract class AbstractHilosLegalDocumentsPage extends AbstractHilosLegalPage
{
    public const string PAGE = HilosPageConstants::HILOS_LEGAL_DOCUMENTS;
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_DOCUMENTS,
    ];

    /**
     * @param string $acceptKey Subscribing connection; only an admin receives catalog diagnostics
     * @param PageRouteParams $params Route parameters, unused
     * @return ?PagePayload Catalog availability without an overview projection
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        try {
            LegalCatalogResolver::documents();
        } catch (LegalException $e) {
            return new PagePayload(data: [self::CATALOG_REFUSAL => $this->failureText($acceptKey, $e)]);
        }

        return new PagePayload(data: [self::CATALOG_REFUSAL => null]);
    }
}
