<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameOverrideDeleteActionDTO;

/** Base for the names of one country. */
abstract class AbstractHilosI18nCountryNamesPage extends AbstractHilosI18nCountryCodePage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_COUNTRY_NAMES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_DELETE => HilosI18nCountryNameDeleteActionDTO::class,
        HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_OVERRIDE_DELETE => HilosI18nCountryNameOverrideDeleteActionDTO::class,
    ];

    /** @var list<string> Sources of the names table of one country (HIL-1477) */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::languages,
        HilosDbContext::locales,
        HilosDbContext::countryNames,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRY_NAMES,
    ];

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed removal request
     * @return ?ActionReplyDTO No reply data; success travels in the tracked acknowledgment
     * @throws AgentUnknownActionException When the action is unknown
     * @throws InvalidActionPayloadException When the payload has the wrong type
     * @throws TableActionException When coordinates are missing or the name changed
     * @throws HilosException When lookup, ownership or deletion fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_DELETE:
                if (!$dto instanceof HilosI18nCountryNameDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountryNameDeleteActionDTO::class, $dto);
                }
                $this->deleteName($dto);

                return null;

            case HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_OVERRIDE_DELETE:
                if (!$dto instanceof HilosI18nCountryNameOverrideDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountryNameOverrideDeleteActionDTO::class, $dto);
                }
                $this->deleteOverride($dto);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @param HilosI18nCountryNameDeleteActionDTO $dto Confirmed base name and its literal text
     * @throws TableActionException When coordinates are missing or the name changed
     * @throws HilosException When lookup, ownership or deletion fails
     */
    private function deleteName(HilosI18nCountryNameDeleteActionDTO $dto): void
    {
        $country = Hilos::$db->countries[$dto->countryCode]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryCode);
        $inLanguage = Hilos::$db->languages[$dto->inLanguageCode]
            ?? throw new TableActionException('Unknown writing language: ' . $dto->inLanguageCode);
        $name = Hilos::$db->countryNames->findBase($country->id, $inLanguage->id)
            ?? throw new TableActionException('The name no longer exists.');
        if ($name->name !== $dto->expectedName) {
            throw new TableActionException('The name changed. Close this window and confirm again.');
        }

        $name->actions->deleteAndRestoreCatalog();
        $this->setActionSuccessMessage('Country name removed.');
    }

    /**
     * @param HilosI18nCountryNameOverrideDeleteActionDTO $dto Confirmed correction and its literal text
     * @throws TableActionException When coordinates are missing, incompatible or the name changed
     * @throws HilosException When lookup, ownership or deletion fails
     */
    private function deleteOverride(HilosI18nCountryNameOverrideDeleteActionDTO $dto): void
    {
        $country = Hilos::$db->countries[$dto->countryCode]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryCode);
        $inLanguage = Hilos::$db->languages[$dto->inLanguageCode]
            ?? throw new TableActionException('Unknown writing language: ' . $dto->inLanguageCode);
        $locale = Hilos::$db->locales[$dto->localeCode]
            ?? throw new TableActionException('Unknown locale: ' . $dto->localeCode);
        if ($locale->countryId === null || $locale->languageId !== $inLanguage->id) {
            throw new TableActionException('The correction must belong to a country locale of the writing language.');
        }
        if (Hilos::$db->countryNames->findBase($country->id, $inLanguage->id) === null) {
            throw new TableActionException('The name no longer exists.');
        }
        $name = Hilos::$db->countryNames->findOverride($country->id, $inLanguage->id, $locale->id)
            ?? throw new TableActionException('The name no longer exists.');
        if ($name->name !== $dto->expectedName) {
            throw new TableActionException('The name changed. Close this window and confirm again.');
        }

        $name->actions->delete();
        $this->setActionSuccessMessage('Locale correction removed.');
    }
}
