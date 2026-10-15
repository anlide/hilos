<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Collection\CountryNames;
use Hilos\Database\View\Collection\Locales;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Locale;
use Hilos\Hilos;
use Hilos\HilosException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the real country rule and bridge, with only the names query doubled. */
final class I18nCountryDeleteReasonTest extends TestCase
{
    private ?HilosDbContext $previousDb;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
    }

    /** @throws HilosException When the catalog lookup fails */
    public function testKnownCountryDoesNotReadLaterDependencies(): void
    {
        Hilos::$db = $this->createMock(HilosDbContext::class);
        Hilos::$db->expects(self::never())->method('__get');
        self::assertSame(Country::DELETE_KNOWN, $this->country('us')->deleteReason());
    }

    /**
     * @param bool $enabled Locale switch position
     * @throws HilosException When the bridge cannot be read
     */
    #[DataProvider('switchPositions')]
    public function testEitherLocaleSwitchBlocksBeforeNames(bool $enabled): void
    {
        $locales = Locales::initEmpty();
        $locales->add($this->locale($enabled));
        $names = $this->createMock(CountryNames::class);
        $names->expects(self::never())->method('hasForCountry');
        $this->mount($locales, $names);
        self::assertSame(Country::DELETE_LOCALES, $this->country('qx')->deleteReason());
        self::assertSame(Country::DELETE_LOCALES, $this->country('qx', enabled: true)->deleteReason());
    }

    /** @return array<string, array{bool}> Both switch positions */
    public static function switchPositions(): array
    {
        return ['off' => [false], 'on' => [true]];
    }

    /** @throws HilosException When the names existence query fails */
    public function testAnyNameBlocksWithoutAskingForNonemptyBaseNames(): void
    {
        $names = $this->createMock(CountryNames::class);
        $names->expects(self::exactly(2))->method('hasForCountry')->with(1)->willReturn(true);
        $names->expects(self::never())->method('countBaseNamesForCountry');
        $names->expects(self::never())->method('findBase');
        $this->mount(Locales::initEmpty(), $names);
        self::assertSame(Country::DELETE_NAMES, $this->country('qx')->deleteReason());
        self::assertSame(Country::DELETE_NAMES, $this->country('qx', enabled: true)->deleteReason());
    }

    /** @throws HilosException When the current dependencies cannot be read */
    public function testRuleIsRecomputedAfterLocaleAndNameChanges(): void
    {
        $locales = Locales::initEmpty();
        $names = $this->createMock(CountryNames::class);
        $names->expects(self::exactly(3))->method('hasForCountry')->with(1)->willReturn(true, false, false);
        $this->mount($locales, $names);
        $country = $this->country('qx');
        $locales->add($this->locale(false));
        self::assertSame(Country::DELETE_LOCALES, $country->deleteReason());
        $locales[10]->getObject()->countryId = null;
        self::assertSame(Country::DELETE_NAMES, $country->deleteReason());
        self::assertNull($country->deleteReason());
        $country->getObject()->enabled = true;
        self::assertNull($country->deleteReason());
    }

    /** @throws HilosException When an unpersisted country's empty bridge cannot be read */
    public function testNullParentSelectsNoCountrylessLocalesAndSkipsNames(): void
    {
        $locales = Locales::initEmpty();
        $locale = $this->locale(false);
        $locale->getObject()->countryId = null;
        $locales->add($locale);
        $names = $this->createMock(CountryNames::class);
        $names->expects(self::never())->method('hasForCountry');
        $this->mount($locales, $names);
        $country = $this->country('qx', id: null);
        self::assertInstanceOf(Locales::class, $country->locales);
        self::assertCount(0, $country->locales);
        self::assertNull($country->deleteReason());
    }

    /** @throws HilosException When filtering the subclass fixture fails */
    public function testBridgeFilterPreservesTheMountedCollectionClass(): void
    {
        $locales = CountryReasonLocales::initEmpty();
        $locales->add($this->locale(false));
        self::assertInstanceOf(CountryReasonLocales::class, $locales->forCountryId(1));
        self::assertInstanceOf(CountryReasonLocales::class, $locales->forCountryId(null));
        self::assertSame($locales[10], $locales->forCountryId(1)[10]);
    }

    /**
     * @param Locales $locales Real manual collection used by the bridge
     * @param CountryNames $names Double of the names query
     */
    private function mount(Locales $locales, CountryNames $names): void
    {
        Hilos::$db = $this->createMock(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnMap([
            [HilosDbContext::locales, $locales],
            [HilosDbContext::countryNames, $names],
        ]);
    }

    /**
     * @param string $code Catalog or own country code
     * @param ?int $id Parent primary key
     * @param bool $enabled Country switch position
     * @return Country Real View item
     */
    private function country(string $code, ?int $id = 1, bool $enabled = false): Country
    {
        $entity = new EntityCountry();
        $entity->id = $id;
        $entity->code = $code;
        $entity->enabled = $enabled;
        return new Country(ObjectCountry::fromEntity($entity));
    }

    /**
     * @param bool $enabled Locale switch position
     * @return Locale Real View item owned by country 1
     */
    private function locale(bool $enabled): Locale
    {
        $entity = new EntityLocale();
        $entity->id = 10;
        $entity->code = 'en-QX';
        $entity->country_id = 1;
        $entity->enabled = $enabled;
        return new Locale(ObjectLocale::fromEntity($entity));
    }
}

/** Manual fixture for the filter's late-static return contract. */
final class CountryReasonLocales extends Locales
{
}
