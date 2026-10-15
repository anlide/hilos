<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\I18n\DTO\CountryCardSummary;
use Hilos\I18n\DTO\CountryLocaleChoice;
use PHPUnit\Framework\TestCase;

/** Pins the serialization boundary and the supplied deletion verdict. */
final class I18nCountryCardSummaryTest extends TestCase
{
    public function testSuppliedDeletionReasonIsSerialized(): void
    {
        $known = new CountryCardSummary(
            'United States', false, 'en-US', null, 1, 1,
            [new CountryLocaleChoice('en-US', false)], 'en-US', CountryCardSummary::DELETE_KNOWN,
        );
        self::assertSame(CountryCardSummary::DELETE_KNOWN, $known->deleteReason);
        self::assertFalse($known->canDelete);
        self::assertSame([['code' => 'en-US', 'enabled' => false]], $known->toArray()['locales']);
        self::assertSame('en-US', $known->toArray()['catalogDefaultLocaleCode']);

        $knownBare = new CountryCardSummary(null, false, null, null, 0, 0, [], null, CountryCardSummary::DELETE_KNOWN);
        self::assertSame(CountryCardSummary::DELETE_KNOWN, $knownBare->deleteReason);

        $locales = new CountryCardSummary('Atlantis', true, null, null, 1, 1, [], null, CountryCardSummary::DELETE_LOCALES);
        self::assertSame(CountryCardSummary::DELETE_LOCALES, $locales->deleteReason);
        self::assertFalse($locales->canDelete);

        $names = new CountryCardSummary(null, true, null, null, 0, 0, [], null, CountryCardSummary::DELETE_NAMES);
        self::assertSame(CountryCardSummary::DELETE_NAMES, $names->deleteReason);
        self::assertFalse($names->canDelete);

        $empty = new CountryCardSummary(null, true, null, null, 0, 0, [], null, null);
        self::assertNull($empty->deleteReason);
        self::assertTrue($empty->canDelete);
        self::assertSame([
            'name' => null,
            'isOwn' => true,
            'defaultLocaleCode' => null,
            'defaultLocale' => CountryCardSummary::DEFAULT_LOCALE_MISSING,
            'localeCount' => 0,
            'nameCount' => 0,
            'locales' => [],
            'catalogDefaultLocaleCode' => null,
            'canDelete' => true,
            'deleteReason' => null,
        ], $empty->toArray());
    }

    public function testDefaultLocaleStates(): void
    {
        self::assertSame(
            CountryCardSummary::DEFAULT_LOCALE_MISSING,
            new CountryCardSummary(null, true, null, null, 0, 0, [], null, null)->defaultLocale,
        );
        self::assertSame(
            CountryCardSummary::DEFAULT_LOCALE_OFF,
            new CountryCardSummary(null, true, 'en-US', false, 0, 0, [], null, null)->defaultLocale,
        );
        self::assertSame(
            CountryCardSummary::DEFAULT_LOCALE_ON,
            new CountryCardSummary(null, true, 'en-US', true, 0, 0, [], null, null)->defaultLocale,
        );
    }
}
