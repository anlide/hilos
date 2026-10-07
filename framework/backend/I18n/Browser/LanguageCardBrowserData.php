<?php

declare(strict_types=1);

namespace Hilos\I18n\Browser;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\CountryName;
use Hilos\Database\Object\Item\Language;
use Hilos\Database\Object\Item\LanguageName;
use Hilos\Database\Object\Item\Locale;

/** One language row and the sources that can change its computed card summary. */
final class LanguageCardBrowserData
{
    public const string DATA = 'languageCard';
    public const string FIELD_SUMMARY = 'summary';

    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            HilosPageRouteParams::HILOS_I18N_LANGUAGE_CODE => [
                BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                BrowserRefKey::KEY => HilosPageRouteParams::HILOS_I18N_LANGUAGE_CODE,
            ],
        ],
    ];

    private const array LANGUAGES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::languages,
    ];
    private const array LOCALES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::locales,
    ];
    private const array LANGUAGE_NAMES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::languageNames,
    ];
    private const array COUNTRY_NAMES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::countryNames,
    ];

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_I18N_LANGUAGE_CODE => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            self::LANGUAGES_SOURCE,
            self::LOCALES_SOURCE,
            self::LANGUAGE_NAMES_SOURCE,
            self::COUNTRY_NAMES_SOURCE,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => self::LANGUAGES_SOURCE,
                BrowserDataFieldKey::ROW_KEY => Language::id,
                BrowserDataFieldKey::WHERE => [
                    Language::code => [
                        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
                        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_I18N_LANGUAGE_CODE,
                    ],
                ],
                BrowserDataFieldKey::FIELDS => [Language::code, Language::nativeName, Language::rtl, Language::enabled],
                BrowserDataFieldKey::COMPUTED => [self::FIELD_SUMMARY],
                BrowserDataFieldKey::NOT_PERSONAL => [self::FIELD_SUMMARY],
            ],
            [
                BrowserDataFieldKey::SOURCE => self::LOCALES_SOURCE,
                BrowserDataFieldKey::ROW_KEY => Locale::languageId,
                BrowserDataFieldKey::MANY => true,
                BrowserDataFieldKey::FIELDS => [],
            ],
            [
                BrowserDataFieldKey::SOURCE => self::LANGUAGE_NAMES_SOURCE,
                BrowserDataFieldKey::ROW_KEY => LanguageName::languageId,
                BrowserDataFieldKey::MANY => true,
                BrowserDataFieldKey::FIELDS => [],
            ],
            [
                BrowserDataFieldKey::SOURCE => self::LANGUAGE_NAMES_SOURCE,
                BrowserDataFieldKey::ROW_KEY => LanguageName::inLanguageId,
                BrowserDataFieldKey::MANY => true,
                BrowserDataFieldKey::FIELDS => [],
            ],
            [
                BrowserDataFieldKey::SOURCE => self::COUNTRY_NAMES_SOURCE,
                BrowserDataFieldKey::ROW_KEY => CountryName::languageId,
                BrowserDataFieldKey::MANY => true,
                BrowserDataFieldKey::FIELDS => [],
            ],
        ],
    ];
}
