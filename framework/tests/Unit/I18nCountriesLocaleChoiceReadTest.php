<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Item\CountryActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Object\Collection\Countries as ObjectCountries;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Collection\Countries;
use Hilos\Database\View\Collection\CountryNames;
use Hilos\Database\View\Collection\Languages;
use Hilos\Database\View\Collection\Locales;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Locale;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\DTO\CountryCardSummary;
use Hilos\I18n\DTO\CountryLocaleChoice;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountriesTableRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real country bridges and projections retain HIL-1496 values without locale SQL helpers. */
final class I18nCountriesLocaleChoiceReadTest extends TestCase
{
    private ?HilosDbContext $previousDb;
    private ?EnvAccessor $previousEnv;
    private string|false $previousDefaultLanguage;

    private Locales $locales;

    /** @throws HilosException When a fixture cannot be assembled */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');

        $objects = [];
        $countries = [];
        foreach ([1 => 'us', 2 => 'qx', 3 => 'qy', 4 => 'qz'] as $id => $code) {
            $objects[$id] = ObjectCountry::fromEntity(EntityCountry::fromRow([
                EntityCountry::id => $id,
                EntityCountry::code => $code,
                EntityCountry::currency_symbol => '¤',
                EntityCountry::currency_code => 'XXX',
                EntityCountry::default_locale_id => $id === 1 ? 10 : null,
                EntityCountry::enabled => false,
            ]));
            $countries[$id] = new Country($objects[$id]);
        }
        $countryObjects = ObjectCountries::initEmpty();
        foreach ($objects as $id => $object) {
            $countryObjects[$id] = $object;
        }
        $countryView = $this->getMockBuilder(Countries::class)->onlyMethods(['queryPageItems'])->getMock();
        $countryView->setObjectCollection($countryObjects);
        $countryView->method('queryPageItems')->willReturn([
            TableConstants::RESULT_KEY_ROWS => array_values($countries),
            TableConstants::RESULT_KEY_TOTAL_COUNT => count($countries),
        ]);

        $this->locales = Locales::initEmpty();
        foreach ([
            $this->locale(10, 1, 'en-US', true),
            $this->locale(11, 1, 'de-US', false),
            $this->locale(12, 2, 'en-QX', false),
            $this->locale(13, null, 'en', true),
        ] as $locale) {
            $this->locales->add($locale);
        }

        $names = $this->createMock(CountryNames::class);
        $names->method('hasForCountry')->willReturnCallback(static fn (int $id): bool => $id === 1 || $id === 2 || $id === 3);
        $languages = $this->createMock(Languages::class);
        $languages->method('offsetGet')->willReturn(null);
        Hilos::$db = $this->createMock(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnMap([
            [HilosDbContext::countries, $countryView],
            [HilosDbContext::locales, $this->locales],
            [HilosDbContext::countryNames, $names],
            [HilosDbContext::languages, $languages],
        ]);
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$env = $this->previousEnv;
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        parent::tearDown();
    }

    /** @throws HilosException When projecting the countries fails */
    public function testSeveralCountriesUseTheirBridgesIncludingCountriesWithoutLocales(): void
    {
        $snapshot = new HilosI18nCountriesTable()->getPage(new TableQueryDTO());

        $rows = [];
        foreach ($snapshot->rows as $row) {
            self::assertInstanceOf(HilosI18nCountriesTableRow::class, $row);
            $rows[$row->code] = $row;
        }
        self::assertCount(4, $rows);
        self::assertSame([
            ['code' => 'de-US', 'enabled' => false],
            ['code' => 'en-US', 'enabled' => true],
        ], $rows['us']->toArray()[HilosI18nCountriesTableRow::locales]);
        self::assertEquals([new CountryLocaleChoice('en-QX', false)], $rows['qx']->locales);
        self::assertSame([], $rows['qy']->locales);
        self::assertSame([], $rows['qz']->locales);
        self::assertSame(CountryCardSummary::DELETE_KNOWN, $rows['us']->deleteReason);
        self::assertSame(CountryCardSummary::DELETE_LOCALES, $rows['qx']->deleteReason);
        self::assertSame(CountryCardSummary::DELETE_NAMES, $rows['qy']->deleteReason);
        self::assertTrue($rows['qz']->canDelete);
    }

    /**
     * @param SourceChange $change Change rebuilding only country qx
     * @throws HilosException When projecting the mutation fails
     */
    #[DataProvider('singleCountryChanges')]
    public function testALiveMutationReadsItsCountryBridge(SourceChange $change): void
    {
        $mutation = new HilosI18nCountriesTable()->buildMutationForSourceEvent($change);

        self::assertNotNull($mutation);
        self::assertSame('qx', $mutation->rowKey);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertInstanceOf(HilosI18nCountriesTableRow::class, $mutation->row);
        self::assertEquals([new CountryLocaleChoice('en-QX', false)], $mutation->row->locales);
    }

    /** @return array<string, array{SourceChange}> Country and locale source paths */
    public static function singleCountryChanges(): array
    {
        return [
            'country update' => [SourceChange::dbUpdated(HilosDbContext::countries, '2', [])],
            'locale create' => [SourceChange::dbCreated(HilosDbContext::locales, '12', [])],
            'locale switch' => [SourceChange::dbUpdated(HilosDbContext::locales, '12', [ObjectLocale::enabled => false])],
            'locale delete' => [SourceChange::dbDeleted(HilosDbContext::locales, '99', [ObjectLocale::countryId => 2])],
        ];
    }

    /** @throws HilosException When resolving the countryless locale fails */
    public function testACountrylessLocaleReadsNoCountryChoices(): void
    {
        self::assertNull(new HilosI18nCountriesTable()->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::locales, '13', []),
        ));
    }

    /** @throws HilosException When projecting the card fails */
    public function testADirectCardReadsFreshChoicesOnEveryCall(): void
    {
        self::assertSame([
            ['code' => 'de-US', 'enabled' => false],
            ['code' => 'en-US', 'enabled' => true],
        ], Hilos::$db->countries->cardSummary(1)->toArray()['locales']);
        self::assertSame(CountryCardSummary::DEFAULT_LOCALE_ON, Hilos::$db->countries->cardSummary(1)->defaultLocale);
        $this->locales[10]->getObject()->enabled = false;
        $this->locales[11]->getObject()->enabled = true;
        self::assertSame([
            ['code' => 'de-US', 'enabled' => true],
            ['code' => 'en-US', 'enabled' => false],
        ], Hilos::$db->countries->cardSummary(1)->toArray()['locales']);
        self::assertSame(CountryCardSummary::DEFAULT_LOCALE_OFF, Hilos::$db->countries->cardSummary(1)->defaultLocale);
        $this->locales[11]->getObject()->countryId = null;
        $this->locales->add($this->locale(14, 1, 'fr-US', true));
        self::assertSame([
            ['code' => 'en-US', 'enabled' => false],
            ['code' => 'fr-US', 'enabled' => true],
        ], Hilos::$db->countries->cardSummary(1)->toArray()['locales']);
    }

    /** @throws HilosException When projecting the table or checking removal fails */
    public function testTableAndDeletionDoNotBuildACardSummary(): void
    {
        $countries = $this->getMockBuilder(Countries::class)->onlyMethods(['cardSummary', 'queryPageItems'])->getMock();
        $countries->setObjectCollection(Hilos::$db->countries->getObjectCollection());
        $countries->expects(self::never())->method('cardSummary');
        $countries->method('queryPageItems')->willReturn([
            TableConstants::RESULT_KEY_ROWS => [Hilos::$db->countries[2]],
            TableConstants::RESULT_KEY_TOTAL_COUNT => 1,
        ]);
        $db = $this->createMock(HilosDbContext::class);
        $db->method('__get')->willReturnMap([
            [HilosDbContext::countries, $countries],
            [HilosDbContext::locales, $this->locales],
            [HilosDbContext::languages, Hilos::$db->languages],
            [HilosDbContext::countryNames, Hilos::$db->countryNames],
        ]);
        Hilos::$db = $db;
        self::assertCount(1, new HilosI18nCountriesTable()->getPage(new TableQueryDTO())->rows);
        $actions = $this->getMockBuilder(CountryActions::class)
            ->setConstructorArgs([$countries[2]])->onlyMethods(['ensureCanWrite'])->getMock();
        $actions->expects(self::once())->method('ensureCanWrite')->with(TruthSourceOperation::Remove);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Country 'qx' cannot be deleted: locales refer to it.");
        $actions->delete();
    }

    /**
     * @param int $id Locale primary key
     * @param ?int $countryId Owning country, absent for a countryless locale
     * @param string $code Locale code
     * @param bool $enabled Locale switch
     * @return Locale Read-only fixture row
     */
    private function locale(int $id, ?int $countryId, string $code, bool $enabled): Locale
    {
        $entity = new EntityLocale();
        $entity->id = $id;
        $entity->country_id = $countryId;
        $entity->code = $code;
        $entity->enabled = $enabled;

        return new Locale(ObjectLocale::fromEntity($entity));
    }
}
