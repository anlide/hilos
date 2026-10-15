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
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameAddActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameOverrideDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameUpdateActionDTO;

/** Base for the names of one country. */
abstract class AbstractHilosI18nCountryNamesPage extends AbstractHilosI18nCountryCodePage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_COUNTRY_NAMES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_ADD => HilosI18nCountryNameAddActionDTO::class,
        HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_UPDATE => HilosI18nCountryNameUpdateActionDTO::class,
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

    private const string NAME_ALREADY_EXISTS_MESSAGE = 'A name already exists for this language or locale.';

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed name mutation request
     * @return ?ActionReplyDTO No reply data; success travels in the tracked acknowledgment
     * @throws AgentUnknownActionException When the action is unknown
     * @throws InvalidActionPayloadException When the payload has the wrong type
     * @throws TableActionException When coordinates are missing or the name changed
     * @throws HilosException When lookup, ownership or persistence fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_ADD:
                if (!$dto instanceof HilosI18nCountryNameAddActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountryNameAddActionDTO::class, $dto);
                }
                $this->addName($dto);

                return null;

            case HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_UPDATE:
                if (!$dto instanceof HilosI18nCountryNameUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nCountryNameUpdateActionDTO::class, $dto);
                }
                $this->updateName($dto);

                return null;

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
     * @param HilosI18nCountryNameAddActionDTO $dto New name and its coordinates
     * @throws TableActionException When coordinates are invalid or a name already exists
     * @throws HilosException When lookup, ownership or persistence fails
     */
    private function addName(HilosI18nCountryNameAddActionDTO $dto): void
    {
        $country = Hilos::$db->countries[$dto->countryId]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryId);
        $inLanguage = Hilos::$db->languages[$dto->inLanguageId]
            ?? throw new TableActionException('Unknown writing language: ' . $dto->inLanguageId);
        $locale = null;
        $name = Hilos::$db->countryNames->findBase($country->id, $inLanguage->id);
        if ($dto->localeId !== null) {
            $locale = Hilos::$db->locales[$dto->localeId]
                ?? throw new TableActionException('Unknown locale: ' . $dto->localeId);
            if ($locale->countryId === null || $locale->languageId !== $inLanguage->id) {
                throw new TableActionException('The correction must belong to a country locale of the writing language.');
            }
            if ($name === null || $name->id !== $dto->baseNameId) {
                throw new TableActionException('The name no longer exists.');
            }
            $name = Hilos::$db->countryNames->findOverride($country->id, $inLanguage->id, $locale->id);
        }
        if ($name !== null) {
            throw new TableActionException(self::NAME_ALREADY_EXISTS_MESSAGE);
        }

        try {
            Hilos::$db->countryNames->actions->createManual($country, $inLanguage, $locale, $dto->name);
        } catch (DuplicateEntryException) {
            throw new TableActionException(self::NAME_ALREADY_EXISTS_MESSAGE);
        }
        $this->setActionSuccessMessage($locale === null ? 'Country name added.' : 'Locale correction added.');
    }

    /**
     * @param HilosI18nCountryNameUpdateActionDTO $dto Name and latest live text acknowledged by the editor
     * @throws TableActionException When coordinates are invalid or the current name differs
     * @throws HilosException When lookup, ownership or persistence fails
     */
    private function updateName(HilosI18nCountryNameUpdateActionDTO $dto): void
    {
        $country = Hilos::$db->countries[$dto->countryId]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryId);
        $inLanguage = Hilos::$db->languages[$dto->inLanguageId]
            ?? throw new TableActionException('Unknown writing language: ' . $dto->inLanguageId);
        if ($dto->localeId !== null) {
            $locale = Hilos::$db->locales[$dto->localeId]
                ?? throw new TableActionException('Unknown locale: ' . $dto->localeId);
            if ($locale->countryId === null || $locale->languageId !== $inLanguage->id) {
                throw new TableActionException('The correction must belong to a country locale of the writing language.');
            }
            if (Hilos::$db->countryNames->findBase($country->id, $inLanguage->id) === null) {
                throw new TableActionException('The name no longer exists.');
            }
        }
        $name = Hilos::$db->countryNames[$dto->nameId]
            ?? throw new TableActionException('The name no longer exists.');
        if ($name->countryId !== $dto->countryId || $name->languageId !== $dto->inLanguageId
            || $name->localeId !== $dto->localeId) {
            throw new TableActionException('The name no longer exists at these coordinates.');
        }
        if ($name->name !== $dto->expectedName) {
            throw new TableActionException('The name changed elsewhere. Review the current value and save again.');
        }

        $name->actions->edit($dto->name);
        $this->setActionSuccessMessage($dto->localeId === null ? 'Country name saved.' : 'Locale correction saved.');
    }

    /**
     * @param HilosI18nCountryNameDeleteActionDTO $dto Confirmed base name and its literal text
     * @throws TableActionException When coordinates are missing or the name changed
     * @throws HilosException When lookup, ownership or deletion fails
     */
    private function deleteName(HilosI18nCountryNameDeleteActionDTO $dto): void
    {
        $country = Hilos::$db->countries[$dto->countryId]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryId);
        $inLanguage = Hilos::$db->languages[$dto->inLanguageId]
            ?? throw new TableActionException('Unknown writing language: ' . $dto->inLanguageId);
        $name = Hilos::$db->countryNames[$dto->nameId]
            ?? throw new TableActionException('The name no longer exists.');
        if ($name->countryId !== $dto->countryId || $name->languageId !== $dto->inLanguageId
            || $name->localeId !== null) {
            throw new TableActionException('The name no longer exists at these coordinates.');
        }
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
        $country = Hilos::$db->countries[$dto->countryId]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryId);
        $inLanguage = Hilos::$db->languages[$dto->inLanguageId]
            ?? throw new TableActionException('Unknown writing language: ' . $dto->inLanguageId);
        $locale = Hilos::$db->locales[$dto->localeId]
            ?? throw new TableActionException('Unknown locale: ' . $dto->localeId);
        if ($locale->countryId === null || $locale->languageId !== $inLanguage->id) {
            throw new TableActionException('The correction must belong to a country locale of the writing language.');
        }
        if (Hilos::$db->countryNames->findBase($country->id, $inLanguage->id) === null) {
            throw new TableActionException('The name no longer exists.');
        }
        $name = Hilos::$db->countryNames[$dto->nameId]
            ?? throw new TableActionException('The name no longer exists.');
        if ($name->countryId !== $dto->countryId || $name->languageId !== $dto->inLanguageId
            || $name->localeId !== $dto->localeId) {
            throw new TableActionException('The name no longer exists at these coordinates.');
        }
        if ($name->name !== $dto->expectedName) {
            throw new TableActionException('The name changed. Close this window and confirm again.');
        }

        $name->actions->delete();
        $this->setActionSuccessMessage('Locale correction removed.');
    }
}
