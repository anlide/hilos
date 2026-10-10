<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\I18n\LocaleTemplates;
use Hilos\Core\Exception\ValidationException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleAddActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleUpdateActionDTO;

/** Base for the locales of one language. */
abstract class AbstractHilosI18nLanguageLocalesPage extends AbstractHilosI18nLanguageCodePage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES;

    public const PageReach REACH = PageReach::ROUTE;

    public const string LOCALE_TEMPLATES_DATA = 'localeTemplates';

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_I18N_LOCALE_ADD => HilosI18nLocaleAddActionDTO::class,
        HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE => HilosI18nLocaleUpdateActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE_LOCALES,
    ];

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO No reply data; success travels in the action acknowledgement
     * @throws AgentUnknownActionException When the action is unknown
     * @throws InvalidActionPayloadException When the payload has the wrong DTO type
     * @throws TableActionException When the addressed language, country or locale is unknown
     * @throws I18nRowFrozenException When the locale is switched on
     * @throws ValidationException When the pair exists or a format is not a known template
     * @throws HilosException When the lookup or write fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_I18N_LOCALE_ADD:
                if (!$dto instanceof HilosI18nLocaleAddActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLocaleAddActionDTO::class, $dto);
                }
                $language = Hilos::$db->languages[$dto->languageCode]
                    ?? throw new TableActionException('Unknown language: ' . $dto->languageCode);
                $country = $dto->countryCode === null ? null : (Hilos::$db->countries[$dto->countryCode]
                    ?? throw new TableActionException('Unknown country: ' . $dto->countryCode));
                $formats = $dto->formats;
                Hilos::$db->locales->actions->create(
                    $language, $country, $formats->date, $formats->time, $formats->number,
                    $formats->phone, $formats->address, $formats->measurement, $formats->collation,
                );
                $this->setActionSuccessMessage('Locale added.');

                return null;

            case HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE:
                if (!$dto instanceof HilosI18nLocaleUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLocaleUpdateActionDTO::class, $dto);
                }
                $code = ObjectLocale::codeFor($dto->languageCode, $dto->countryCode);
                $locale = Hilos::$db->locales[$code]
                    ?? throw new TableActionException('Unknown locale: ' . $code);
                $formats = $dto->formats;
                $locale->actions->update(
                    $formats->date, $formats->time, $formats->number, $formats->phone,
                    $formats->address, $formats->measurement, $formats->collation,
                );
                $this->setActionSuccessMessage('Locale saved.');

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @param string $acceptKey Subscribing connection (unused)
     * @param PageRouteParams $params Route parameters (unused)
     * @return PagePayload Closed format template lists for the locale window
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [self::LOCALE_TEMPLATES_DATA => LocaleTemplates::toArray()]);
    }

    /** @return array<string, WireField> Non-personal format template lists */
    protected function dataFields(): array
    {
        return [self::LOCALE_TEMPLATES_DATA => WireField::notPersonal()];
    }
}
