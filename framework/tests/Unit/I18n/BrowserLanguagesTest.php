<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n;

use Hilos\I18n\BrowserLanguages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A browser's request for languages reads into tags of the reference's shape, by weight, and never refuses. */
final class BrowserLanguagesTest extends TestCase
{
    public function testOrdersByWeight(): void
    {
        self::assertSame(
            ['fr', 'de', 'en'],
            BrowserLanguages::fromAcceptLanguage('en;q=0.3, de;q=0.7, fr')->tags,
        );
    }

    public function testEqualWeightsKeepHeaderOrder(): void
    {
        self::assertSame(
            ['uk', 'pl', 'ru', 'cs', 'en'],
            BrowserLanguages::fromAcceptLanguage('ru;q=0.8, cs;q=0.8, uk, en;q=0.5, pl;q=1.000')->tags,
        );
    }

    public function testZeroWeightAndWildcardAreLeftOut(): void
    {
        self::assertSame(
            ['de', 'fr'],
            BrowserLanguages::fromAcceptLanguage('en;q=0, de, *;q=0.5, fr;q=0.1, it;q=0.000')->tags,
        );
    }

    public function testUnreadableWeightDropsOnlyItsItem(): void
    {
        self::assertSame(
            ['de', 'it'],
            BrowserLanguages::fromAcceptLanguage('en;q=1.5, de;q=0.9, fr;q=0.1234, pl;q=, cs;level=1, it;Q=0.2')->tags,
        );
    }

    /**
     * @return array<string, array{string, list<string>}> Header and the tags it reads into
     */
    public static function provideTagShapes(): array
    {
        return [
            'script and region' => ['zh-Hant-TW', ['zh-TW']],
            'script alone' => ['zh-Hant', ['zh']],
            'numeric region' => ['es-419', ['es']],
            'case' => ['EN-us', ['en-US']],
            'extension' => ['en-US-u-ca-gregory', ['en-US']],
            'three-letter language' => ['fil-PH', ['fil-PH']],
            'language out of shape' => ['i-klingon, x-private, english, en', ['en']],
        ];
    }

    /**
     * @param string $header Raw header
     * @param list<string> $tags Tags it reads into
     */
    #[DataProvider('provideTagShapes')]
    public function testTagTakesReferenceShape(string $header, array $tags): void
    {
        self::assertSame($tags, BrowserLanguages::fromAcceptLanguage($header)->tags);
    }

    public function testRepeatAfterReadingKeepsFirstPlace(): void
    {
        self::assertSame(
            ['ru-RU', 'en', 'zh-TW'],
            BrowserLanguages::fromAcceptLanguage('ru-ru;q=0.9, en;q=0.5, ru-RU;q=0.4, EN;q=0.3, zh-Hant-TW;q=0.2, zh-TW;q=0.1')->tags,
        );
    }

    public function testKeepsAtMostMaxTags(): void
    {
        $codes = [
            'aa', 'ab', 'ac', 'ad', 'ae', 'af', 'ag', 'ah', 'ai', 'aj', 'ak', 'al', 'am', 'an', 'ao', 'ap', 'aq',
        ];

        $tags = BrowserLanguages::fromAcceptLanguage(implode(',', $codes))->tags;

        self::assertSame(BrowserLanguages::MAX_TAGS, count($tags));
        self::assertSame(array_slice($codes, 0, BrowserLanguages::MAX_TAGS), $tags);
    }

    public function testMissingEmptyOrBrokenHeaderIsEmpty(): void
    {
        self::assertSame([], BrowserLanguages::fromAcceptLanguage(null)->tags);
        self::assertSame([], BrowserLanguages::fromAcceptLanguage('')->tags);
        self::assertSame([], BrowserLanguages::fromAcceptLanguage(';;,')->tags);
    }

    public function testStoredFormRoundTrips(): void
    {
        $browser = BrowserLanguages::fromAcceptLanguage('ru-UA, ru;q=0.9, en-US;q=0.8, en;q=0.7');

        self::assertSame('ru-UA,ru,en-US,en', $browser->toStored());
        self::assertSame($browser->tags, BrowserLanguages::fromStored($browser->toStored())->tags);
    }

    public function testEmptyStoredFormIsNull(): void
    {
        self::assertNull(BrowserLanguages::fromAcceptLanguage(null)->toStored());
        self::assertNull(BrowserLanguages::fromAcceptLanguage('*')->toStored());
        self::assertSame([], BrowserLanguages::fromStored(null)->tags);
    }

    public function testLanguagesAreUniqueInOrder(): void
    {
        self::assertSame(['ru', 'uk'], BrowserLanguages::fromAcceptLanguage('ru-UA, ru, uk, ru-RU')->languages());
    }

    public function testVariantsOfKeepOrderAndLeaveOtherLanguagesOut(): void
    {
        $browser = BrowserLanguages::fromAcceptLanguage('ru-UA, ru, rus-RU, en-RU, ru-RU');

        self::assertSame(['ru-UA', 'ru-RU'], $browser->variantsOf('ru'));
        self::assertSame([], $browser->variantsOf('uk'));
    }
}
