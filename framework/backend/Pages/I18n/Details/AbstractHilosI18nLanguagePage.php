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
use Hilos\I18n\Exception\DefaultLanguageProtectedException;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageSwitchOffActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageSwitchOnActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageUpdateActionDTO;
use Hilos\Pages\I18n\HilosI18nLanguageUpdateTrait;

/**
 * AbstractHilosI18nLanguagePage - Abstract base for Hilos i18n language page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage).
 */
abstract class AbstractHilosI18nLanguagePage extends AbstractHilosI18nLanguageCodePage
{
    use HilosI18nLanguageUpdateTrait;

    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGE;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF => HilosI18nLanguageSwitchOffActionDTO::class,
        HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON => HilosI18nLanguageSwitchOnActionDTO::class,
        HilosSignalConstants::HILOS_I18N_LANGUAGE_UPDATE => HilosI18nLanguageUpdateActionDTO::class,
    ];

    /** @var list<string> Sources of the main language card */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::locales,
        HilosDbContext::languageNames,
        HilosDbContext::countryNames,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE,
    ];

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO No reply data; success is carried by the action acknowledgement
     * @throws AgentUnknownActionException When the action is unknown
     * @throws InvalidActionPayloadException When the action payload has the wrong type
     * @throws TableActionException When the language does not exist
     * @throws DefaultLanguageProtectedException When the language is the configured default
     * @throws I18nRowFrozenException When the edited language is switched on
     * @throws ValidationException When the native name is invalid
     * @throws HilosException When the lookup or write fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF:
                if (!$dto instanceof HilosI18nLanguageSwitchOffActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLanguageSwitchOffActionDTO::class, $dto);
                }

                $language = Hilos::$db->languages[$dto->languageCode]
                    ?? throw new TableActionException('Unknown language: ' . $dto->languageCode);
                $language->actions->switchOff();
                $this->setActionSuccessMessage('Language switched off.');

                return null;

            case HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON:
                if (!$dto instanceof HilosI18nLanguageSwitchOnActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLanguageSwitchOnActionDTO::class, $dto);
                }

                $language = Hilos::$db->languages[$dto->languageCode]
                    ?? throw new TableActionException('Unknown language: ' . $dto->languageCode);
                $language->actions->switchOn();
                $this->setActionSuccessMessage('Language switched on.');

                return null;

            case HilosSignalConstants::HILOS_I18N_LANGUAGE_UPDATE:
                if (!$dto instanceof HilosI18nLanguageUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLanguageUpdateActionDTO::class, $dto);
                }

                $this->updateLanguage($dto);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }
}
