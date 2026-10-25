<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\I18n\DTO\LanguageCardSummary;
use PHPUnit\Framework\TestCase;

/** Pins the visible deletion verdict without requiring a database. */
final class I18nLanguageCardSummaryTest extends TestCase
{
    public function testDeletionReasonUsesDefaultLocalesThenManualNames(): void
    {
        $default = new LanguageCardSummary(true, false, 2, 0, 4, null, true, true);
        self::assertSame(LanguageCardSummary::DELETE_DEFAULT, $default->deleteReason);
        self::assertFalse($default->canDelete);

        $locales = new LanguageCardSummary(false, true, 1, 0, 0, false, true, true);
        self::assertSame(LanguageCardSummary::DELETE_LOCALES, $locales->deleteReason);

        $languageName = new LanguageCardSummary(false, true, 0, 0, 0, null, true, false);
        self::assertSame(LanguageCardSummary::DELETE_NAMES, $languageName->deleteReason);

        $countryName = new LanguageCardSummary(false, true, 0, 0, 0, null, false, true);
        self::assertSame(LanguageCardSummary::DELETE_NAMES, $countryName->deleteReason);

        $empty = new LanguageCardSummary(false, true, 0, 0, 0, null, false, false);
        self::assertNull($empty->deleteReason);
        self::assertTrue($empty->canDelete);
        self::assertSame([
            'isDefault' => false,
            'isOwn' => true,
            'localeCount' => 0,
            'enabledLocaleCount' => 0,
            'nameCount' => 0,
            'countrylessLocale' => LanguageCardSummary::COUNTRYLESS_MISSING,
            'canDelete' => true,
            'deleteReason' => null,
        ], $empty->toArray());
    }

    public function testEnabledLocaleCountIsSerializedWithoutChangingTheDeletionVerdict(): void
    {
        $summary = new LanguageCardSummary(false, true, 3, 2, 4, true, false, false);
        self::assertSame(2, $summary->enabledLocaleCount);
        self::assertSame([
            'isDefault' => false,
            'isOwn' => true,
            'localeCount' => 3,
            'enabledLocaleCount' => 2,
            'nameCount' => 4,
            'countrylessLocale' => LanguageCardSummary::COUNTRYLESS_ON,
            'canDelete' => false,
            'deleteReason' => LanguageCardSummary::DELETE_LOCALES,
        ], $summary->toArray());
    }

    public function testCountrylessLocaleStates(): void
    {
        self::assertSame(
            LanguageCardSummary::COUNTRYLESS_MISSING,
            new LanguageCardSummary(false, true, 0, 0, 0, null, false, false)->countrylessLocale,
        );
        self::assertSame(
            LanguageCardSummary::COUNTRYLESS_OFF,
            new LanguageCardSummary(false, true, 0, 0, 0, false, false, false)->countrylessLocale,
        );
        self::assertSame(
            LanguageCardSummary::COUNTRYLESS_ON,
            new LanguageCardSummary(false, true, 0, 0, 0, true, false, false)->countrylessLocale,
        );
    }
}
