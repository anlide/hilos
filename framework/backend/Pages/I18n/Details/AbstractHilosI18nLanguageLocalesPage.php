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
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Exception\DefaultLocaleProtectedException;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\I18n\LocaleTemplates;
use Hilos\Core\Exception\ValidationException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleAddActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleSwitchOffActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleSwitchOnActionDTO;
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
        HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON => HilosI18nLocaleSwitchOnActionDTO::class,
        HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF => HilosI18nLocaleSwitchOffActionDTO::class,
        HilosSignalConstants::HILOS_I18N_LOCALE_DELETE => HilosI18nLocaleDeleteActionDTO::class,
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
     * @throws DefaultLocaleProtectedException When switching off the default language's countryless locale
     * @throws ValidationException When the pair exists, a format is unknown or a reference prevents deletion
     * @throws HilosException When the lookup or write fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_I18N_LOCALE_ADD:
                if (!$dto instanceof HilosI18nLocaleAddActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLocaleAddActionDTO::class, $dto);
                }
                $language = Hilos::$db->languages[$dto->languageId]
                    ?? throw new TableActionException('Unknown language: ' . $dto->languageId);
                $country = $dto->countryId === null ? null : (Hilos::$db->countries[$dto->countryId]
                    ?? throw new TableActionException('Unknown country: ' . $dto->countryId));
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
                $locale = Hilos::$db->locales[$dto->localeId]
                    ?? throw new TableActionException('Unknown locale: ' . $dto->localeId);
                if ($locale->languageId !== $dto->languageId || $locale->countryId !== $dto->countryId) {
                    throw new TableActionException('The locale no longer belongs to this language and country.');
                }
                $formats = $dto->formats;
                $locale->actions->update(
                    $formats->date, $formats->time, $formats->number, $formats->phone,
                    $formats->address, $formats->measurement, $formats->collation,
                );
                $this->setActionSuccessMessage('Locale saved.');

                return null;

            case HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON:
                if (!$dto instanceof HilosI18nLocaleSwitchOnActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLocaleSwitchOnActionDTO::class, $dto);
                }
                $locale = Hilos::$db->locales[$dto->localeId]
                    ?? throw new TableActionException('Unknown locale: ' . $dto->localeId);
                if ($locale->languageId !== $dto->languageId || $locale->countryId !== $dto->countryId) {
                    throw new TableActionException('The locale no longer belongs to this language and country.');
                }
                $locale->actions->switchOn();
                $this->setActionSuccessMessage('Locale switched on.');

                return null;

            case HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF:
                if (!$dto instanceof HilosI18nLocaleSwitchOffActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLocaleSwitchOffActionDTO::class, $dto);
                }

                return $this->handleLocaleSwitchOff($dto);

            case HilosSignalConstants::HILOS_I18N_LOCALE_DELETE:
                if (!$dto instanceof HilosI18nLocaleDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLocaleDeleteActionDTO::class, $dto);
                }

                return $this->handleLocaleDelete($dto);

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

    /**
     * @param HilosI18nLocaleSwitchOffActionDTO $dto Locale address
     * @return ?ActionReplyDTO No reply data; success travels in the action acknowledgement
     * @throws TableActionException When the locale is unknown
     * @throws DefaultLocaleProtectedException When this is the default language's countryless locale
     * @throws HilosException When the lookup or write fails
     */
    private function handleLocaleSwitchOff(HilosI18nLocaleSwitchOffActionDTO $dto): ?ActionReplyDTO
    {
        $locale = Hilos::$db->locales[$dto->localeId]
            ?? throw new TableActionException('Unknown locale: ' . $dto->localeId);
        if ($locale->languageId !== $dto->languageId || $locale->countryId !== $dto->countryId) {
            throw new TableActionException('The locale no longer belongs to this language and country.');
        }
        $locale->actions->switchOff();
        $this->setActionSuccessMessage('Locale switched off.');

        return null;
    }

    /**
     * @param HilosI18nLocaleDeleteActionDTO $dto Locale address
     * @return ?ActionReplyDTO No reply data; success travels in the action acknowledgement
     * @throws TableActionException When the locale is unknown
     * @throws I18nRowFrozenException When the locale is switched on
     * @throws ValidationException When the locale is a country's default or has name overrides
     * @throws HilosException When the lookup or write fails
     */
    private function handleLocaleDelete(HilosI18nLocaleDeleteActionDTO $dto): ?ActionReplyDTO
    {
        $locale = Hilos::$db->locales[$dto->localeId]
            ?? throw new TableActionException('Unknown locale: ' . $dto->localeId);
        if ($locale->languageId !== $dto->languageId || $locale->countryId !== $dto->countryId) {
            throw new TableActionException('The locale no longer belongs to this language and country.');
        }
        $locale->actions->delete();
        $this->setActionSuccessMessage('Locale deleted.');

        return null;
    }
}
