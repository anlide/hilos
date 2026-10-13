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
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameOverrideDeleteActionDTO;

/** Base for the names of one language. */
abstract class AbstractHilosI18nLanguageNamesPage extends AbstractHilosI18nLanguageCodePage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_DELETE => HilosI18nLanguageNameDeleteActionDTO::class,
        HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_OVERRIDE_DELETE => HilosI18nLanguageNameOverrideDeleteActionDTO::class,
    ];

    /** @var list<string> Sources of the names table of one language (HIL-1477) */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::locales,
        HilosDbContext::languageNames,
        HilosDbContext::countries,
        HilosDbContext::countryNames,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE_NAMES,
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
            case HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_DELETE:
                if (!$dto instanceof HilosI18nLanguageNameDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLanguageNameDeleteActionDTO::class, $dto);
                }
                $this->deleteName($dto);

                return null;

            case HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_OVERRIDE_DELETE:
                if (!$dto instanceof HilosI18nLanguageNameOverrideDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLanguageNameOverrideDeleteActionDTO::class, $dto);
                }
                $this->deleteOverride($dto);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @param HilosI18nLanguageNameDeleteActionDTO $dto Confirmed base name and its literal text
     * @throws TableActionException When coordinates are missing or the name changed
     * @throws HilosException When lookup, ownership or deletion fails
     */
    private function deleteName(HilosI18nLanguageNameDeleteActionDTO $dto): void
    {
        $language = Hilos::$db->languages[$dto->languageCode]
            ?? throw new TableActionException('Unknown language: ' . $dto->languageCode);
        $inLanguage = Hilos::$db->languages[$dto->inLanguageCode]
            ?? throw new TableActionException('Unknown writing language: ' . $dto->inLanguageCode);
        if ($language->id === $inLanguage->id) {
            throw new TableActionException('A language name must be written in another language.');
        }
        $name = Hilos::$db->languageNames->findBase($language->id, $inLanguage->id)
            ?? throw new TableActionException('The name no longer exists.');
        if ($name->name !== $dto->expectedName) {
            throw new TableActionException('The name changed. Close this window and confirm again.');
        }

        $name->actions->delete();
        $this->setActionSuccessMessage('Language name deleted.');
    }

    /**
     * @param HilosI18nLanguageNameOverrideDeleteActionDTO $dto Confirmed correction and its literal text
     * @throws TableActionException When coordinates are missing, incompatible or the name changed
     * @throws HilosException When lookup, ownership or deletion fails
     */
    private function deleteOverride(HilosI18nLanguageNameOverrideDeleteActionDTO $dto): void
    {
        $language = Hilos::$db->languages[$dto->languageCode]
            ?? throw new TableActionException('Unknown language: ' . $dto->languageCode);
        $inLanguage = Hilos::$db->languages[$dto->inLanguageCode]
            ?? throw new TableActionException('Unknown writing language: ' . $dto->inLanguageCode);
        if ($language->id === $inLanguage->id) {
            throw new TableActionException('A language name must be written in another language.');
        }
        $locale = Hilos::$db->locales[$dto->localeCode]
            ?? throw new TableActionException('Unknown locale: ' . $dto->localeCode);
        if ($locale->countryId === null || $locale->languageId !== $inLanguage->id) {
            throw new TableActionException('The correction must belong to a country locale of the writing language.');
        }
        if (Hilos::$db->languageNames->findBase($language->id, $inLanguage->id) === null) {
            throw new TableActionException('The name no longer exists.');
        }
        $name = Hilos::$db->languageNames->findOverride($language->id, $inLanguage->id, $locale->id)
            ?? throw new TableActionException('The name no longer exists.');
        if ($name->name !== $dto->expectedName) {
            throw new TableActionException('The name changed. Close this window and confirm again.');
        }

        $name->actions->delete();
        $this->setActionSuccessMessage('Locale correction removed.');
    }
}
