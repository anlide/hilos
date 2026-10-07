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
        $default = new LanguageCardSummary(true, false, 2, 4, true, true);
        self::assertSame(LanguageCardSummary::DELETE_DEFAULT, $default->deleteReason);
        self::assertFalse($default->canDelete);

        $locales = new LanguageCardSummary(false, true, 1, 0, true, true);
        self::assertSame(LanguageCardSummary::DELETE_LOCALES, $locales->deleteReason);

        $languageName = new LanguageCardSummary(false, true, 0, 0, true, false);
        self::assertSame(LanguageCardSummary::DELETE_NAMES, $languageName->deleteReason);

        $countryName = new LanguageCardSummary(false, true, 0, 0, false, true);
        self::assertSame(LanguageCardSummary::DELETE_NAMES, $countryName->deleteReason);

        $empty = new LanguageCardSummary(false, true, 0, 0, false, false);
        self::assertNull($empty->deleteReason);
        self::assertTrue($empty->canDelete);
        self::assertSame([
            'isDefault' => false,
            'isOwn' => true,
            'localeCount' => 0,
            'nameCount' => 0,
            'canDelete' => true,
            'deleteReason' => null,
        ], $empty->toArray());
    }
}
