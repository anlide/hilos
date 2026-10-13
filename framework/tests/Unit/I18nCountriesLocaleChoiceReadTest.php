<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
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

/**
 * Counts locale-choice reads through the real table and both cardSummary layers (HIL-1496).
 * The locale read doors are doubles; neither summary assembly nor its deletion verdict is mocked.
 * Counts and names are separate reads and deliberately outside this regression's budget.
 */
final class I18nCountriesLocaleChoiceReadTest extends TestCase
{
    private ?HilosDbContext $previousDb;
    private ?EnvAccessor $previousEnv;
    private string|false $previousDefaultLanguage;

    /** @var list<int> Country ids whose choices were read individually */
    private array $singleReads = [];

    /** @var list<list<int|string>> Country ids passed to each grouped read */
    private array $batchReads = [];

    /** @var array<int, list<CountryLocaleChoice>> Current answers of the locale read double */
    private array $choices = [];

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
                EntityCountry::default_locale_id => null,
                EntityCountry::enabled => false,
            ]));
            $countries[$id] = new Country($objects[$id]);
        }
        $countryObjects = $this->getMockBuilder(ObjectCountries::class)->onlyMethods(['offsetGet'])->getMock();
        $countryObjects->method('offsetGet')->willReturnCallback(static fn (mixed $id): ?ObjectCountry => $objects[$id] ?? null);
        $countryView = $this->getMockBuilder(Countries::class)->onlyMethods(['queryPageItems', 'offsetGet'])->getMock();
        $countryView->setObjectCollection($countryObjects);
        $countryView->method('queryPageItems')->willReturn([
            TableConstants::RESULT_KEY_ROWS => array_values($countries),
            TableConstants::RESULT_KEY_TOTAL_COUNT => count($countries),
        ]);
        $countryView->method('offsetGet')->willReturnCallback(static fn (mixed $id): ?Country => $countries[$id] ?? null);

        $this->choices = [
            1 => [new CountryLocaleChoice('de-US', false), new CountryLocaleChoice('en-US', true)],
            2 => [new CountryLocaleChoice('en-QX', false)],
        ];
        $localeRows = [
            10 => $this->locale(10, 1, 'en-US', true),
            11 => $this->locale(11, 1, 'de-US', false),
            12 => $this->locale(12, 2, 'en-QX', false),
            13 => $this->locale(13, null, 'en', true),
        ];
        $locales = $this->createMock(Locales::class);
        $locales->method('offsetGet')->willReturnCallback(static fn (mixed $id): ?Locale => $localeRows[$id] ?? null);
        $locales->method('countForCountry')->willReturnCallback(fn (int $id): int => count($this->choices[$id] ?? []));
        $locales->method('choicesForCountry')->willReturnCallback(function (int $id): array {
            $this->singleReads[] = $id;
            return $this->choices[$id] ?? [];
        });
        $locales->method('whereColumnIn')->willReturnCallback(function (string $column, int|string ...$ids) use ($locales): Locales {
            self::assertSame(ObjectLocale::countryId, $column);
            $this->batchReads[] = $ids;
            return $locales;
        });
        $locales->method('getIterator')->willReturnCallback(static function () use ($localeRows): Generator {
            foreach ($localeRows as $locale) {
                if ($locale->countryId !== null) {
                    yield $locale;
                }
            }
        });

        $names = $this->createMock(CountryNames::class);
        $names->method('hasForCountry')->willReturnCallback(static fn (int $id): bool => $id === 1 || $id === 2 || $id === 3);
        $languages = $this->createMock(Languages::class);
        $languages->method('offsetGet')->willReturn(null);
        Hilos::$db = $this->createMock(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnMap([
            [HilosDbContext::countries, $countryView],
            [HilosDbContext::locales, $locales],
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
    public function testSeveralCountriesShareOneChoiceReadIncludingCountriesWithoutLocales(): void
    {
        $snapshot = new HilosI18nCountriesTable()->getPage(new TableQueryDTO());

        self::assertSame([[1, 2, 3, 4]], $this->batchReads);
        self::assertSame([], $this->singleReads, 'A grouped read must not be followed by one choice read per card summary.');
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
        self::assertEquals($this->choices[2], $rows['qx']->locales);
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
    public function testALiveMutationReadsItsCountryChoicesOnce(SourceChange $change): void
    {
        $mutation = new HilosI18nCountriesTable()->buildMutationForSourceEvent($change);

        self::assertSame([2], $this->singleReads, 'The summary and row must share the same choice read.');
        self::assertSame([], $this->batchReads);
        self::assertNotNull($mutation);
        self::assertSame('qx', $mutation->rowKey);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertInstanceOf(HilosI18nCountriesTableRow::class, $mutation->row);
        self::assertEquals($this->choices[2], $mutation->row->locales);
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
        self::assertSame([], $this->singleReads);
        self::assertSame([], $this->batchReads);
    }

    /** @throws HilosException When projecting the card fails */
    public function testADirectCardReadsFreshChoicesOnEveryCall(): void
    {
        self::assertEquals($this->choices[1], Hilos::$db->countries->cardSummary(1)->locales);
        $this->choices[1] = [new CountryLocaleChoice('de-US', true), new CountryLocaleChoice('en-US', false)];
        self::assertEquals($this->choices[1], Hilos::$db->countries->cardSummary(1)->locales);
        self::assertSame([1, 1], $this->singleReads);
        self::assertSame([], $this->batchReads);
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
