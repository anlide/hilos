<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\I18n\Details\AbstractHilosI18nCountryNamesPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nCountryPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nLanguageLocalesPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nLanguageNamesPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nLanguagePage;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryPageSubscribeParams;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguagePageSubscribeParams;
use PHPUnit\Framework\TestCase;

/** Pins the shared address contract of the five i18n detail pages. */
final class I18nPageAddressTest extends TestCase
{
    public function testLanguageCodeRequiresExactlyTwoLowercaseAsciiLetters(): void
    {
        self::assertSame(
            'en',
            HilosI18nLanguagePageSubscribeParams::fromPageRouteParams(new PageRouteParams(['languageCode' => 'en']))->languageCode,
        );

        foreach ([[], ['languageCode' => '']] as $raw) {
            try {
                HilosI18nLanguagePageSubscribeParams::fromPageRouteParams(new PageRouteParams($raw));
                self::fail('Expected missing languageCode');
            } catch (MissingPageRouteParamException $e) {
                self::assertSame(400, $e->httpCode);
                self::assertSame(HilosPageRouteParams::HILOS_I18N_LANGUAGE_CODE, $e->paramKey);
            }
        }

        foreach (['e', 'eng', 'EN', 'e1', 'é', "en\n"] as $code) {
            try {
                HilosI18nLanguagePageSubscribeParams::fromPageRouteParams(new PageRouteParams(['languageCode' => $code]));
                self::fail('Expected invalid languageCode');
            } catch (InvalidPageRouteParamException $e) {
                self::assertSame(400, $e->httpCode);
                self::assertSame(HilosPageRouteParams::HILOS_I18N_LANGUAGE_CODE, $e->paramKey);
            }
        }
    }

    public function testCountryCodeRequiresExactlyTwoLowercaseAsciiLetters(): void
    {
        self::assertSame(
            'pl',
            HilosI18nCountryPageSubscribeParams::fromPageRouteParams(new PageRouteParams(['countryCode' => 'pl']))->countryCode,
        );

        foreach ([[], ['countryCode' => '']] as $raw) {
            try {
                HilosI18nCountryPageSubscribeParams::fromPageRouteParams(new PageRouteParams($raw));
                self::fail('Expected missing countryCode');
            } catch (MissingPageRouteParamException $e) {
                self::assertSame(400, $e->httpCode);
                self::assertSame(HilosPageRouteParams::HILOS_I18N_COUNTRY_CODE, $e->paramKey);
            }
        }

        foreach (['p', 'pol', 'PL', 'p1', 'ż', "pl\n"] as $code) {
            try {
                HilosI18nCountryPageSubscribeParams::fromPageRouteParams(new PageRouteParams(['countryCode' => $code]));
                self::fail('Expected invalid countryCode');
            } catch (InvalidPageRouteParamException $e) {
                self::assertSame(400, $e->httpCode);
                self::assertSame(HilosPageRouteParams::HILOS_I18N_COUNTRY_CODE, $e->paramKey);
            }
        }
    }

    public function testFivePagesKeepTheirDistinctKeysAndBrowserSignals(): void
    {
        foreach ([
            [
                AbstractHilosI18nLanguagePage::class,
                HilosPageConstants::HILOS_I18N_LANGUAGE,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE,
            ],
            [
                AbstractHilosI18nLanguageNamesPage::class,
                HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE_NAMES,
            ],
            [
                AbstractHilosI18nLanguageLocalesPage::class,
                HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE_LOCALES,
            ],
            [
                AbstractHilosI18nCountryPage::class,
                HilosPageConstants::HILOS_I18N_COUNTRY,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRY,
            ],
            [
                AbstractHilosI18nCountryNamesPage::class,
                HilosPageConstants::HILOS_I18N_COUNTRY_NAMES,
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRY_NAMES,
            ],
        ] as [$pageClass, $page, $signal]) {
            self::assertSame($page, $pageClass::PAGE);
            self::assertSame(PageReach::ROUTE, $pageClass::REACH);
            self::assertSame($signal, $pageClass::BROWSER[BrowserConfigKey::SIGNAL]);
        }
    }
}
