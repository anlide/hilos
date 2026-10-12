<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountrySwitchOffActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountrySwitchOnActionDTO;

/**
 * AbstractHilosI18nCountryPage - Abstract base for Hilos i18n country page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage).
 */
abstract class AbstractHilosI18nCountryPage extends AbstractHilosI18nCountryCodePage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_COUNTRY;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON => HilosI18nCountrySwitchOnActionDTO::class,
        HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF => HilosI18nCountrySwitchOffActionDTO::class,
        HilosSignalConstants::HILOS_I18N_COUNTRY_DELETE => HilosI18nCountryDeleteActionDTO::class,
    ];

    /** @var list<string> Sources of the main country card */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::locales,
        HilosDbContext::countryNames,
        HilosDbContext::languages,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRY,
    ];

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO No reply data; success is carried by the action acknowledgement
     * @throws AgentUnknownActionException When the action is unknown
     * @throws InvalidActionPayloadException When the action payload has the wrong type
     * @throws TableActionException When the country does not exist
     * @throws ValidationException When the catalog or references protect the country from deletion
     * @throws HilosException When the lookup or write fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON:
                if (!$dto instanceof HilosI18nCountrySwitchOnActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountrySwitchOnActionDTO::class, $dto);
                }

                $country = Hilos::$db->countries[$dto->countryCode]
                    ?? throw new TableActionException('Unknown country: ' . $dto->countryCode);
                $country->actions->switchOn();
                $this->setActionSuccessMessage('Country switched on.');

                return null;

            case HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF:
                if (!$dto instanceof HilosI18nCountrySwitchOffActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountrySwitchOffActionDTO::class, $dto);
                }

                $country = Hilos::$db->countries[$dto->countryCode]
                    ?? throw new TableActionException('Unknown country: ' . $dto->countryCode);
                $country->actions->switchOff();
                $this->setActionSuccessMessage('Country switched off.');

                return null;

            case HilosSignalConstants::HILOS_I18N_COUNTRY_DELETE:
                if (!$dto instanceof HilosI18nCountryDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountryDeleteActionDTO::class, $dto);
                }
                $this->handleCountryDelete($dto);

                return null;

            default:
                throw new AgentUnknownActionException($action, static::class);
        }
    }

    /**
     * @param HilosI18nCountryDeleteActionDTO $dto Country address
     * @throws TableActionException When the country does not exist
     * @throws ValidationException When the catalog or references protect the country
     * @throws HilosException When the canonical deletion check or write refuses removal
     */
    private function handleCountryDelete(HilosI18nCountryDeleteActionDTO $dto): void
    {
        $country = Hilos::$db->countries[$dto->countryCode]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryCode);
        $country->actions->delete();
        $this->setActionSuccessMessage('Country deleted.');
    }
}
