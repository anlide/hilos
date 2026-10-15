<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\AbstractHilosI18nCountryNamesPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nCountryPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nLanguageLocalesPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nLanguageNamesPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nLanguagePage;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameAddActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameOverrideDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameUpdateActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountrySwitchOffActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountrySwitchOnActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryUpdateActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameAddActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameOverrideDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameUpdateActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageSwitchOffActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageSwitchOnActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageUpdateActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleAddActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleSwitchOffActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleSwitchOnActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleUpdateActionDTO;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nCountriesListPage;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nLanguagesListPage;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryAddActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryRowDeleteActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryRowUpdateActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageAddActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageRowDeleteActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageRowUpdateActionDTO;
use PHPUnit\Framework\TestCase;

/** Classify every registered i18n action by its actual parsed and serialized contract. */
final class I18nActionIdContractTest extends TestCase
{
    /** @throws HilosException When an action rejects its declared contract */
    public function testEveryRegisteredActionHasAnExactClassifiedContract(): void
    {
        $formats = [
            'date' => 'yyyy-MM-dd', 'time' => 'HH:mm', 'number' => '1,234.5',
            'phone' => '', 'address' => '', 'measurement' => 'metric', 'collation' => '',
        ];
        $contracts = [
            HilosI18nLanguageAddActionDTO::class => [
                'languageCode' => 'qv',
                'nativeName' => 'Name',
                'rtl' => false,
            ],
            HilosI18nLanguageUpdateActionDTO::class => [
                'languageId' => 11,
                'nativeName' => 'Name',
                'rtl' => false,
            ],
            HilosI18nLanguageRowUpdateActionDTO::class => [
                'languageId' => 11,
                'nativeName' => 'Name',
                'rtl' => false,
            ],
            HilosI18nLanguageSwitchOnActionDTO::class => [
                'languageId' => 11,
            ],
            HilosI18nLanguageSwitchOffActionDTO::class => [
                'languageId' => 11,
            ],
            HilosI18nLanguageDeleteActionDTO::class => [
                'languageId' => 11,
            ],
            HilosI18nLanguageRowDeleteActionDTO::class => [
                'languageId' => 11,
            ],
            HilosI18nCountryAddActionDTO::class => [
                'countryCode' => 'qv',
                'currencySymbol' => '$',
                'currencyCode' => 'USD',
            ],
            HilosI18nCountryUpdateActionDTO::class => [
                'countryId' => 11,
                'currencySymbol' => '$',
                'currencyCode' => 'USD',
                'defaultLocaleId' => null,
            ],
            HilosI18nCountryRowUpdateActionDTO::class => [
                'countryId' => 11,
                'currencySymbol' => '$',
                'currencyCode' => 'USD',
                'defaultLocaleId' => null,
            ],
            HilosI18nCountrySwitchOnActionDTO::class => [
                'countryId' => 11,
            ],
            HilosI18nCountrySwitchOffActionDTO::class => [
                'countryId' => 11,
            ],
            HilosI18nCountryDeleteActionDTO::class => [
                'countryId' => 11,
            ],
            HilosI18nCountryRowDeleteActionDTO::class => [
                'countryId' => 11,
            ],
            HilosI18nLocaleAddActionDTO::class => [
                'languageId' => 11,
                'countryId' => null,
                'formats' => $formats,
            ],
            HilosI18nLocaleUpdateActionDTO::class => [
                'languageId' => 11,
                'countryId' => null,
                'localeId' => 21,
                'formats' => $formats,
            ],
            HilosI18nLocaleSwitchOnActionDTO::class => [
                'languageId' => 11,
                'countryId' => null,
                'localeId' => 21,
            ],
            HilosI18nLocaleSwitchOffActionDTO::class => [
                'languageId' => 11,
                'countryId' => null,
                'localeId' => 21,
            ],
            HilosI18nLocaleDeleteActionDTO::class => [
                'languageId' => 11,
                'countryId' => null,
                'localeId' => 21,
            ],
            HilosI18nLanguageNameAddActionDTO::class => [
                'languageId' => 11,
                'inLanguageId' => 12,
                'localeId' => null,
                'baseNameId' => null,
                'name' => '',
            ],
            HilosI18nLanguageNameUpdateActionDTO::class => [
                'languageId' => 11,
                'inLanguageId' => 12,
                'localeId' => null,
                'nameId' => 31,
                'name' => '',
                'expectedName' => '',
            ],
            HilosI18nLanguageNameDeleteActionDTO::class => [
                'languageId' => 11,
                'inLanguageId' => 12,
                'nameId' => 31,
                'expectedName' => '',
            ],
            HilosI18nLanguageNameOverrideDeleteActionDTO::class => [
                'languageId' => 11,
                'inLanguageId' => 12,
                'localeId' => 21,
                'nameId' => 31,
                'expectedName' => '',
            ],
            HilosI18nCountryNameAddActionDTO::class => [
                'countryId' => 11,
                'inLanguageId' => 12,
                'localeId' => null,
                'baseNameId' => null,
                'name' => '',
            ],
            HilosI18nCountryNameUpdateActionDTO::class => [
                'countryId' => 11,
                'inLanguageId' => 12,
                'localeId' => null,
                'nameId' => 31,
                'name' => '',
                'expectedName' => '',
            ],
            HilosI18nCountryNameDeleteActionDTO::class => [
                'countryId' => 11,
                'inLanguageId' => 12,
                'nameId' => 31,
                'expectedName' => '',
            ],
            HilosI18nCountryNameOverrideDeleteActionDTO::class => [
                'countryId' => 11,
                'inLanguageId' => 12,
                'localeId' => 21,
                'nameId' => 31,
                'expectedName' => '',
            ],
        ];
        $routes = [
            ...AbstractHilosI18nLanguagesListPage::ACTIONS,
            ...AbstractHilosI18nCountriesListPage::ACTIONS,
            ...AbstractHilosI18nLanguagePage::ACTIONS,
            ...AbstractHilosI18nCountryPage::ACTIONS,
            ...AbstractHilosI18nLanguageLocalesPage::ACTIONS,
            ...AbstractHilosI18nLanguageNamesPage::ACTIONS,
            ...AbstractHilosI18nCountryNamesPage::ACTIONS,
        ];
        self::assertCount(27, $routes);
        self::assertCount(27, $contracts);
        self::assertEqualsCanonicalizing(array_values($routes), array_keys($contracts));
        foreach ($routes as $action => $class) {
            $payload = $contracts[$class];
            $dto = $class::fromArray($payload);
            self::assertInstanceOf(ActionPayloadDTO::class, $dto);
            self::assertSame($action, $dto->getAction());
            self::assertSame($payload, $dto->toArray(), $action);
            self::assertSame($payload, $class::fromJson($dto->toJson())->toArray(), $action);
        }
    }
}
