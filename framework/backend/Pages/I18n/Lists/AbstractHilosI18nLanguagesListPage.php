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
use Hilos\I18n\DTO\BuiltInCatalogTally;
use Hilos\I18n\DTO\BuiltInLanguages;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\Pages\I18n\HilosI18nLanguageUpdateTrait;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageAddActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageRowUpdateActionDTO;

/**
 * AbstractHilosI18nLanguagesListPage - Abstract base for Hilos i18n languages list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage).
 */
abstract class AbstractHilosI18nLanguagesListPage extends AbstractHilosPage
{
    use HilosI18nLanguageUpdateTrait;

    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD => HilosI18nLanguageAddActionDTO::class,
        HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_UPDATE => HilosI18nLanguageRowUpdateActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGES,
    ];

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO No reply data; success travels in the action acknowledgement
     * @throws AgentUnknownActionException When the action is unknown
     * @throws InvalidActionPayloadException When the payload has the wrong DTO type
     * @throws ValidationException When the code exists or the language or catalog names are invalid
     * @throws TableActionException When the edited language does not exist
     * @throws I18nRowFrozenException When the edited language is switched on
     * @throws HilosException When ownership, persistence or the transaction refuses the write
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD:
                if (!$dto instanceof HilosI18nLanguageAddActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLanguageAddActionDTO::class, $dto);
                }

                Hilos::$db->languages->actions->add($dto->languageCode, $dto->nativeName, $dto->rtl);
                $this->setActionSuccessMessage(BuiltInI18nCatalog::language($dto->languageCode) === null
                    ? "Custom language {$dto->languageCode} added and switched off for now."
                    : 'Language added and switched off for now.');

                return null;

            case HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_UPDATE:
                if (!$dto instanceof HilosI18nLanguageRowUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosI18nLanguageRowUpdateActionDTO::class, $dto);
                }

                $this->updateLanguage($dto);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @param string $acceptKey Subscribing connection accept key
     * @param PageRouteParams $params Route params from page subscription
     * @return PagePayload Page payload with built-in catalog tally and language definitions
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload(data: [
            BuiltInCatalogTally::DATA => BuiltInCatalogTally::fromCatalog()->toArray(),
            BuiltInLanguages::DATA => BuiltInLanguages::fromCatalog()->toArray(),
        ]);
    }

    /**
     * @return array<string, WireField> Data key to visibility rule
     */
    protected function dataFields(): array
    {
        return [
            BuiltInCatalogTally::DATA => WireField::notPersonal(),
            BuiltInLanguages::DATA => WireField::notPersonal(),
        ];
    }
}

