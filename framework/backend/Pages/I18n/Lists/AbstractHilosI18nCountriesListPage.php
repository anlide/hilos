<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\CountryUpdate;
use Hilos\I18n\DTO\BuiltInCatalogTally;
use Hilos\I18n\DTO\BuiltInCountryCodes;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryAddActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryRowDeleteActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryRowUpdateActionDTO;

/**
 * AbstractHilosI18nCountriesListPage - Abstract base for Hilos i18n countries list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage).
 */
abstract class AbstractHilosI18nCountriesListPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_COUNTRIES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_I18N_COUNTRY_ADD => HilosI18nCountryAddActionDTO::class,
        HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_UPDATE => HilosI18nCountryRowUpdateActionDTO::class,
        HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_DELETE => HilosI18nCountryRowDeleteActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRIES,
    ];

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO No reply data; success travels in the action acknowledgement
     * @throws AgentUnknownActionException When the action is unknown
     * @throws InvalidActionPayloadException When the payload has the wrong DTO type
     * @throws I18nRowFrozenException When an edit targets a switched-on country
     * @throws ValidationException When creation, editing or deletion violates the country policy
     * @throws TableActionException When the country to edit or remove does not exist
     * @throws HilosException When ownership, the database or collection refuses the write
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_I18N_COUNTRY_ADD:
                if (!$dto instanceof HilosI18nCountryAddActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountryAddActionDTO::class, $dto);
                }
                Hilos::$db->countries->actions->create($dto->countryCode, $dto->currencySymbol, $dto->currencyCode);
                $this->setActionSuccessMessage(BuiltInI18nCatalog::country($dto->countryCode) === null
                    ? "Custom country {$dto->countryCode} added and switched off for now."
                    : 'Country added and switched off for now.');

                return null;

            case HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_UPDATE:
                if (!$dto instanceof HilosI18nCountryRowUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountryRowUpdateActionDTO::class, $dto);
                }
                $this->handleCountryUpdate($dto);

                return null;

            case HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_DELETE:
                if (!$dto instanceof HilosI18nCountryRowDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountryRowDeleteActionDTO::class, $dto);
                }
                $this->handleCountryRowDelete($dto);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @param string $acceptKey Subscribing connection accept key
     * @param PageRouteParams $params Route params from page subscription
     * @return PagePayload Page payload with built-in catalog tally and country codes
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [
            BuiltInCatalogTally::DATA => BuiltInCatalogTally::fromCatalog()->toArray(),
            BuiltInCountryCodes::DATA => BuiltInCountryCodes::fromCatalog()->toArray(),
        ]);
    }

    /**
     * @return array<string, WireField> Data key to visibility rule
     */
    protected function dataFields(): array
    {
        return [
            BuiltInCatalogTally::DATA => WireField::notPersonal(),
            BuiltInCountryCodes::DATA => WireField::notPersonal(),
        ];
    }

    /**
     * @param HilosI18nCountryRowUpdateActionDTO $dto Country fields from its table row
     * @throws TableActionException When the country does not exist
     * @throws I18nRowFrozenException When the country is switched on
     * @throws ValidationException When the currency or default locale is invalid
     * @throws HilosException When the lookup or write fails
     */
    private function handleCountryUpdate(HilosI18nCountryRowUpdateActionDTO $dto): void
    {
        CountryUpdate::apply($dto);
        $this->setActionSuccessMessage('Country saved.');
    }

    /**
     * @param HilosI18nCountryRowDeleteActionDTO $dto Country address
     * @throws TableActionException When the country does not exist
     * @throws ValidationException When the catalog or references protect the country
     * @throws HilosException When the canonical deletion check or write refuses removal
     */
    private function handleCountryRowDelete(HilosI18nCountryRowDeleteActionDTO $dto): void
    {
        $country = Hilos::$db->countries[$dto->countryId]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryId);
        $country->actions->delete();
        $this->setActionSuccessMessage('Country deleted.');
    }
}
