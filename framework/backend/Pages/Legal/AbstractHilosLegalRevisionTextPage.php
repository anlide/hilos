<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Pages\Legal\DTO\HilosLegalRevisionPageSubscribeParams;

/** ADMIN subscription sharing the revision payload only for a declared revision. */
abstract class AbstractHilosLegalRevisionTextPage extends AbstractHilosLegalRevisionPage
{
    public const string PAGE = HilosPageConstants::HILOS_LEGAL_REVISION_TEXT;
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_REVISION_TEXT,
    ];

    protected const bool REQUIRE_DECLARED = true;

    /**
     * A faulty catalog keeps the tab reachable so the page can deliver its refusal.
     *
     * @param PageRouteParams $ownerParams Revision owner's route parameters
     * @return bool Whether the requested revision is declared or the catalog refuses
     * @throws MissingPageRouteParamException When a required key is missing
     */
    public static function isTabApplicable(PageRouteParams $ownerParams): bool
    {
        $route = HilosLegalRevisionPageSubscribeParams::fromPageRouteParams($ownerParams);
        try {
            $documents = LegalCatalogResolver::documents();
            $kind = LegalDocument::tryFrom($route->documentKey);
            if ($kind !== null && in_array($kind, $documents, true)) {
                foreach (LegalCatalogResolver::revisions($kind) as $revision) {
                    if ($revision->id === $route->revisionId) {
                        return true;
                    }
                }
            }
            return false;
        } catch (LegalException) {
            return true;
        }
    }
}
