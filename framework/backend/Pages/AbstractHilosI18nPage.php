<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\I18n\DTO\I18nOverviewData;

/**
 * AbstractHilosI18nPage - Abstract base for Hilos i18n page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18nPage).
 */
abstract class AbstractHilosI18nPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N,
    ];

    public const array READS_DB = [HilosDbContext::languages, HilosDbContext::countries];

    /**
     * @param SourceChange $change Committed source fact offered to the overview
     * @return bool Whether any mutation of this source requires a complete overview
     */
    public static function observesOverviewChange(SourceChange $change): bool
    {
        return $change->kind === SourceChange::KIND_DB && in_array($change->sourceKey, self::READS_DB, true);
    }

    /**
     * @param string $acceptKey Subscribing connection accept key
     * @param PageRouteParams $params Route params from page subscription
     * @return PagePayload Complete installation overview
     * @throws DatabaseException When reading the reference fails
     * @throws ItemNotFoundException When the required default language row is missing
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     * @throws EnvException When the default language environment key is absent
     * @throws EnvInvalidValueException When the configured default code is unknown
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [I18nOverviewData::DATA => I18nOverviewData::fromCollections()->toArray()]);
    }

    /**
     * @return array<string, WireField> Computed overview visibility for an admin-view-mode viewer
     */
    protected function dataFields(): array
    {
        return [I18nOverviewData::DATA => WireField::notPersonal()];
    }
}
