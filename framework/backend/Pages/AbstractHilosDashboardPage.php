<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogResolver;

/**
 * AbstractHilosDashboardPage - Abstract base for Hilos dashboard page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\DashboardPage).
 */
abstract class AbstractHilosDashboardPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_DASHBOARD;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DASHBOARD,
    ];

    /**
     * Sends the dashboard its cards: every section, with each item's page key and identity.
     *
     * The dashboard is the one page that needs more of the catalog than its own entry, and it is
     * also the only page the cards are drawn on, so they ride its subscription rather than a
     * delivery of their own. A card is drawn for a section item whether or not the project
     * registered that page - hiding what a project has not activated is a question for the
     * feature registry, not for the catalog.
     *
     * The item lookup is not guarded: topology validation refuses a section naming a page with no
     * catalog entry before the daemon serves anything, so a card without identity cannot reach
     * here, and a guard would swallow the typo the startup check exists to show.
     *
     * @param PageRouteParams $params Route params from page subscription
     * @return ?PagePayload Dashboard sections in display order
     */
    protected function buildPagePayload(PageRouteParams $params): ?PagePayload
    {
        $sections = [];
        foreach (PageCatalogResolver::dashboardSections() as $section) {
            $items = [];
            foreach ($section[PageCatalogConstants::SECTION_ITEMS] as $page) {
                $items[] = [PageCatalogConstants::WIRE_ITEM_PAGE => $page]
                    + PageCatalogResolver::identity($page);
            }

            $sections[] = [
                PageCatalogConstants::SECTION_TITLE => $section[PageCatalogConstants::SECTION_TITLE],
                PageCatalogConstants::SECTION_DESCRIPTION => $section[PageCatalogConstants::SECTION_DESCRIPTION],
                PageCatalogConstants::SECTION_ITEMS => $items,
            ];
        }

        return new PagePayload(data: [PageCatalogConstants::WIRE_DASHBOARD_SECTIONS => $sections]);
    }
}
