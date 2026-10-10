<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\I18n\DTO\BuiltInCatalogTally;

/**
 * AbstractHilosI18nCountriesListPage - Abstract base for Hilos i18n countries list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage).
 */
abstract class AbstractHilosI18nCountriesListPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_COUNTRIES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRIES,
    ];

    /**
     * @param string $acceptKey Subscribing connection accept key
     * @param PageRouteParams $params Route params from page subscription
     * @return PagePayload Page payload with built-in catalog tally
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [
            BuiltInCatalogTally::DATA => BuiltInCatalogTally::fromCatalog()->toArray(),
        ]);
    }

    /**
     * @return array<string, WireField> Data key to visibility rule
     */
    protected function dataFields(): array
    {
        return [
            BuiltInCatalogTally::DATA => WireField::notPersonal(),
        ];
    }
}
