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
use Hilos\Database\Object\Item\Country;
use Hilos\Database\Object\Item\CountryName;
use Hilos\Database\Object\Item\Locale;

/** One country row and the sources that can change its computed card summary. */
final class CountryCardBrowserData
{
    public const string DATA = 'countryCard';
    public const string FIELD_SUMMARY = 'summary';

    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            HilosPageRouteParams::HILOS_I18N_COUNTRY_CODE => [
                BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                BrowserRefKey::KEY => HilosPageRouteParams::HILOS_I18N_COUNTRY_CODE,
            ],
        ],
    ];

    private const array COUNTRIES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::countries,
    ];
    private const array LOCALES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::locales,
    ];
    private const array COUNTRY_NAMES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::countryNames,
    ];

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_I18N_COUNTRY_CODE => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            self::COUNTRIES_SOURCE,
            self::LOCALES_SOURCE,
            self::COUNTRY_NAMES_SOURCE,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => self::COUNTRIES_SOURCE,
                BrowserDataFieldKey::ROW_KEY => Country::id,
                BrowserDataFieldKey::WHERE => [
                    Country::code => [
                        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
                        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_I18N_COUNTRY_CODE,
                    ],
                ],
                BrowserDataFieldKey::FIELDS => [Country::code, Country::currencySymbol, Country::currencyCode, Country::enabled],
                BrowserDataFieldKey::COMPUTED => [self::FIELD_SUMMARY],
                BrowserDataFieldKey::NOT_PERSONAL => [self::FIELD_SUMMARY],
            ],
            [
                BrowserDataFieldKey::SOURCE => self::LOCALES_SOURCE,
                BrowserDataFieldKey::ROW_KEY => Locale::countryId,
                BrowserDataFieldKey::MANY => true,
                BrowserDataFieldKey::FIELDS => [],
            ],
            [
                BrowserDataFieldKey::SOURCE => self::COUNTRY_NAMES_SOURCE,
                BrowserDataFieldKey::ROW_KEY => CountryName::countryId,
                BrowserDataFieldKey::MANY => true,
                BrowserDataFieldKey::FIELDS => [],
            ],
        ],
    ];
}
