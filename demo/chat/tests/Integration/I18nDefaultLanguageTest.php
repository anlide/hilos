<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\HilosException;
use Hilos\I18n\Exception\DefaultLanguageProtectedException;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;

/** The library provisions one current default and item actions guard every write door. */
final class I18nDefaultLanguageTest extends IntegrationTestCase
{
    private const string FAILURE_TRIGGER = 'hil_1471_fail_locale_insert';

    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        $this->clearFixtures();
    }

    protected function tearDown(): void
    {
        Database::sqlRun('DROP TRIGGER IF EXISTS `' . self::FAILURE_TRIGGER . '`');
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        parent::tearDown();
    }

    public function testFirstStartCreatesEnabledLanguageAndLocaleAndSecondStartKeepsThem(): void
    {
        $this->startLibrary();

        $language = Hilos::$db->languages['en'];
        $locale = Hilos::$db->locales['en'];
        self::assertNotNull($language);
        self::assertNotNull($locale);
        self::assertTrue($language->enabled);
        self::assertTrue($locale->enabled);
        self::assertSame('English', $language->nativeName);
        self::assertSame('en', $locale->code);
        self::assertSame($language->id, $locale->languageId);

        $languageId = $language->id;
        $localeId = $locale->id;
        $dateFormat = $locale->dateFormat;
        $this->underAgent($this->agent, fn (): mixed => $this->agent->onStart());

        self::assertSame($languageId, Hilos::$db->languages['en']?->id);
        self::assertSame($localeId, Hilos::$db->locales['en']?->id);
        self::assertSame($dateFormat, Hilos::$db->locales['en']?->dateFormat);
        self::assertSame(1, $this->rowCount('hilos_language', 'en'));
        self::assertSame(1, $this->rowCount('hilos_locale', 'en'));
    }

    public function testExistingDisabledRowsAreEnabledWithoutReplacingEditedValues(): void
    {
        $this->startLibraryClaims();
        $this->underAgent($this->agent, static function (): void {
            $language = Hilos::$db->languages->actions->create('en', 'Custom English', true);
            Hilos::$db->locales->actions->create(
                $language,
                null,
                'custom date',
                'custom time',
                'custom number',
                'custom phone',
                'custom address',
                MeasurementSystem::METRIC,
                'und',
            );
        });

        $this->underAgent($this->agent, fn (): mixed => $this->agent->onStart());

        self::assertSame('Custom English', Hilos::$db->languages['en']?->nativeName);
        self::assertTrue(Hilos::$db->languages['en']?->rtl);
        self::assertTrue(Hilos::$db->languages['en']?->enabled);
        self::assertSame('custom date', Hilos::$db->locales['en']?->dateFormat);
        self::assertTrue(Hilos::$db->locales['en']?->enabled);
    }

    public function testEnvChangeMovesProtectionAndKeepsPreviousLanguageEnabled(): void
    {
        $this->startLibrary();
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=ru');
        $this->underAgent($this->agent, fn (): mixed => $this->agent->onStart());

        self::assertTrue(Hilos::$db->languages['en']?->enabled);
        self::assertTrue(Hilos::$db->languages['ru']?->enabled);
        $this->underAgent($this->agent, static function (): void {
            // The start took the catalog's country names in; the language goes without them (HIL-1488 deletes them along).
            $englishId = Hilos::$db->languages['en']->id;
            foreach (iterator_to_array(Hilos::$db->countryNames) as $name) {
                if ($name->languageId === $englishId) {
                    $name->actions->delete();
                }
            }
            Hilos::$db->languages['en']->actions->switchOff();
            Hilos::$db->locales['en']->actions->switchOff();
            Hilos::$db->locales['en']->actions->delete();
            Hilos::$db->languages['en']->actions->delete();
        });
        self::assertNull(Hilos::$db->languages['en']);

        try {
            $this->underAgent($this->agent, static function (): void {
                Hilos::$db->languages['ru']->actions->delete();
            });
            self::fail('The current default language was deleted');
        } catch (DefaultLanguageProtectedException $failure) {
            self::assertInstanceOf(ValidationException::class, $failure);
            self::assertStringContainsString('ru', $failure->getMessage());
        }
    }

    public function testSwitchOffRefusesEvenWhenTheDefaultRowWasAlreadyDisabled(): void
    {
        $this->startLibrary();
        Database::sqlRun("UPDATE hilos_language SET enabled = 0 WHERE code = 'en'");
        Hilos::$db->languages->getObjectCollection()?->reHydrate();
        Hilos::$db->languages->clearCache();

        try {
            $this->underAgent($this->agent, static function (): void {
                Hilos::$db->languages['en']->actions->switchOff();
            });
            self::fail('The disabled default was accepted');
        } catch (DefaultLanguageProtectedException $failure) {
            self::assertStringContainsString('en', $failure->getMessage());
            self::assertStringContainsString('switched off', $failure->getMessage());
        }
    }

    public function testLocaleFailureRollsBackTheNewLanguage(): void
    {
        Database::sqlRun(
            'CREATE TRIGGER `' . self::FAILURE_TRIGGER . '` BEFORE INSERT ON `hilos_locale` '
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced locale refusal'",
        );
        try {
            $this->startLibrary();
            self::fail('The locale insert was expected to fail');
        } catch (HilosException $failure) {
            self::assertStringContainsString('forced locale refusal', $failure->getMessage());
        }

        self::assertSame(0, $this->rowCount('hilos_language', 'en'));
        self::assertSame(0, $this->rowCount('hilos_locale', 'en'));
        self::assertNull(Hilos::$db->languages['en']);
    }

    private function startLibrary(): void
    {
        $this->underAgent($this->agent, fn (): mixed => $this->startAgent($this->agent));
    }

    private function startLibraryClaims(): void
    {
        OwnershipDeclaration::claimAll($this->agent);
    }

    /** A start takes the whole catalog in, so its countries, names and record go before the languages. */
    private function clearFixtures(): void
    {
        Database::sqlRun('DELETE FROM hilos_country_name');
        Database::sqlRun("DELETE FROM hilos_locale WHERE code IN ('en', 'ru')");
        Database::sqlRun('DELETE FROM hilos_country');
        Database::sqlRun('DELETE FROM hilos_i18n_reflow');
        Database::sqlRun("DELETE FROM hilos_language WHERE code IN ('en', 'ru')");
        foreach ([
            Hilos::$db->countryNames,
            Hilos::$db->locales,
            Hilos::$db->countries,
            Hilos::$db->i18nReflows,
            Hilos::$db->languages,
        ] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }

    private function rowCount(string $table, string $code): int
    {
        Database::sql("SELECT COUNT(*) AS `count` FROM `{$table}` WHERE `code` = ?", [$code]);
        return (int)Database::field('count');
    }
}
