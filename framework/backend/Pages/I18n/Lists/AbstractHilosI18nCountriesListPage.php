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
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DTO\BuiltInCatalogTally;
use Hilos\I18n\DTO\BuiltInCountryCodes;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryAddActionDTO;

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
     * @throws ValidationException When the country exists or its currency is invalid
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
}
