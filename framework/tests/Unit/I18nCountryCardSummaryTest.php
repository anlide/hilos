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
        $known = new CountryCardSummary('United States', false, 'en-US', true, true);
        self::assertSame(CountryCardSummary::DELETE_KNOWN, $known->deleteReason);
        self::assertFalse($known->canDelete);

        $knownBare = new CountryCardSummary(null, false, null, false, false);
        self::assertSame(CountryCardSummary::DELETE_KNOWN, $knownBare->deleteReason);

        $locales = new CountryCardSummary('Atlantis', true, null, true, true);
        self::assertSame(CountryCardSummary::DELETE_LOCALES, $locales->deleteReason);
        self::assertFalse($locales->canDelete);

        $names = new CountryCardSummary(null, true, null, false, true);
        self::assertSame(CountryCardSummary::DELETE_NAMES, $names->deleteReason);
        self::assertFalse($names->canDelete);

        $empty = new CountryCardSummary(null, true, null, false, false);
        self::assertNull($empty->deleteReason);
        self::assertTrue($empty->canDelete);
        self::assertSame([
            'name' => null,
            'isOwn' => true,
            'defaultLocaleCode' => null,
            'canDelete' => true,
            'deleteReason' => null,
        ], $empty->toArray());
    }
}
