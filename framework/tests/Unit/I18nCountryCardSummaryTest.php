<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\I18n\DTO\CountryCardSummary;
use PHPUnit\Framework\TestCase;

/** Pins the visible deletion verdict without requiring a database. */
final class I18nCountryCardSummaryTest extends TestCase
{
    public function testDeletionReasonUsesCatalogLocalesThenNames(): void
    {
        $known = new CountryCardSummary('United States', false, 'en-US', null, true, true);
        self::assertSame(CountryCardSummary::DELETE_KNOWN, $known->deleteReason);
        self::assertFalse($known->canDelete);

        $knownBare = new CountryCardSummary(null, false, null, null, false, false);
        self::assertSame(CountryCardSummary::DELETE_KNOWN, $knownBare->deleteReason);

        $locales = new CountryCardSummary('Atlantis', true, null, null, true, true);
        self::assertSame(CountryCardSummary::DELETE_LOCALES, $locales->deleteReason);
        self::assertFalse($locales->canDelete);

        $names = new CountryCardSummary(null, true, null, null, false, true);
        self::assertSame(CountryCardSummary::DELETE_NAMES, $names->deleteReason);
        self::assertFalse($names->canDelete);

        $empty = new CountryCardSummary(null, true, null, null, false, false);
        self::assertNull($empty->deleteReason);
        self::assertTrue($empty->canDelete);
        self::assertSame([
            'name' => null,
            'isOwn' => true,
            'defaultLocaleCode' => null,
            'defaultLocale' => CountryCardSummary::DEFAULT_LOCALE_MISSING,
            'canDelete' => true,
            'deleteReason' => null,
        ], $empty->toArray());
    }

    public function testDefaultLocaleStates(): void
    {
        self::assertSame(
            CountryCardSummary::DEFAULT_LOCALE_MISSING,
            new CountryCardSummary(null, true, null, null, false, false)->defaultLocale,
        );
        self::assertSame(
            CountryCardSummary::DEFAULT_LOCALE_OFF,
            new CountryCardSummary(null, true, 'en-US', false, false, false)->defaultLocale,
        );
        self::assertSame(
            CountryCardSummary::DEFAULT_LOCALE_ON,
            new CountryCardSummary(null, true, 'en-US', true, false, false)->defaultLocale,
        );
    }
}
