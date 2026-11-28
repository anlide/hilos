<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Schema\Schema;
use Hilos\HilosException;

/**
 * Validates the first weekday migration of hilos_locale: the catalog's day by code, Monday for an own pair,
 * no default left on the column, and the column gone on the way down (HIL-2318).
 */
final class LocaleFirstWeekdayMigrationTest extends IntegrationTestCase
{
    private const int MIGRATION_BEFORE = 175;
    private const int MIGRATION_FIRST_WEEKDAY = 176;
    private const string OWN_COUNTRY = 'qb';

    /** Migration level this shared test database had before this case rewound it. */
    private ?int $latestMigrationIndex = null;

    /** @var list<int> Locale rows this case inserted */
    private array $insertedLocales = [];

    /** @var array<int, int> Switch of each locale row this case found, by id, to put back */
    private array $foundLocaleSwitches = [];

    /** @var list<int> Country rows this case inserted */
    private array $insertedCountries = [];

    /** @var list<int> Language rows this case inserted */
    private array $insertedLanguages = [];

    /**
     * @throws HilosException When migration or fixture statement fails
     */
    public function testUpFillsTheCatalogDayByCodeAndDownDropsTheColumn(): void
    {
        $this->ensureMigrationPath();

        self::assertSame(
            $this->latestMigrationIndex - self::MIGRATION_BEFORE,
            Migration::migrateDown(self::MIGRATION_BEFORE),
        );
        Schema::reset();
        self::assertSame([], Database::sql("SHOW COLUMNS FROM `hilos_locale` LIKE 'first_weekday'")->rows());

        $american = $this->locale('en-US', 'en', 'us', true);
        $british = $this->locale('en-GB', 'en', 'gb', false);
        $egyptian = $this->locale('ar-EG', 'ar', 'eg', false);
        $own = $this->locale('en-QB', 'en', self::OWN_COUNTRY, false);

        self::assertSame(1, Migration::migrateUp(self::MIGRATION_FIRST_WEEKDAY));
        Schema::reset();
        Schema::initialize();

        self::assertSame(0, $this->firstWeekday($american));
        self::assertSame(1, $this->firstWeekday($british));
        self::assertSame(6, $this->firstWeekday($egyptian));
        self::assertSame(1, $this->firstWeekday($own));
        $column = Database::sql("SHOW COLUMNS FROM `hilos_locale` LIKE 'first_weekday'")->row();
        self::assertNotNull($column);
        self::assertMatchesRegularExpression('/^tinyint(?:\([0-9]+\))? unsigned$/i', (string)$column['Type']);
        self::assertSame('NO', (string)$column['Null']);
        self::assertNull($column['Default']);

        self::assertSame(1, Migration::migrateDown(self::MIGRATION_BEFORE));
        Schema::reset();

        self::assertSame([], Database::sql("SHOW COLUMNS FROM `hilos_locale` LIKE 'first_weekday'")->rows());
    }

    /**
     * Restores the fixture rows and the migration level for cases that follow this one in the shared suite.
     *
     * @throws HilosException When cleaning up, replaying a migration or rebuilding schema fails
     */
    protected function tearDown(): void
    {
        try {
            foreach ($this->insertedLocales as $id) {
                Database::sqlRun('DELETE FROM `hilos_locale` WHERE `id` = ?', [$id]);
            }
            foreach ($this->foundLocaleSwitches as $id => $enabled) {
                Database::sqlRun('UPDATE `hilos_locale` SET `enabled` = ? WHERE `id` = ?', [$enabled, $id]);
            }
            foreach ($this->insertedCountries as $id) {
                Database::sqlRun('DELETE FROM `hilos_country` WHERE `id` = ?', [$id]);
            }
            foreach ($this->insertedLanguages as $id) {
                Database::sqlRun('DELETE FROM `hilos_language` WHERE `id` = ?', [$id]);
            }
            Database::sqlRun('UPDATE `migration` SET `failed` = 0 WHERE `index` = ?', [self::MIGRATION_FIRST_WEEKDAY]);
            if ($this->latestMigrationIndex !== null) {
                Migration::migrateUp($this->latestMigrationIndex);
                Schema::reset();
                Schema::initialize();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @throws HilosException When setting up the migration path or migrating up fails
     */
    private function ensureMigrationPath(): void
    {
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        Database::sqlRun('UPDATE `migration` SET `failed` = 0 WHERE `index` = ?', [self::MIGRATION_FIRST_WEEKDAY]);
        $this->latestMigrationIndex = Migration::getCurrentIndex();
        if ($this->latestMigrationIndex < self::MIGRATION_FIRST_WEEKDAY) {
            Migration::migrateUp(self::MIGRATION_FIRST_WEEKDAY);
            $this->latestMigrationIndex = Migration::getCurrentIndex();
        }
        self::assertGreaterThanOrEqual(self::MIGRATION_FIRST_WEEKDAY, $this->latestMigrationIndex);
    }

    /**
     * A locale row as the table held it before the migration; a row already there is taken and switched as asked.
     *
     * @param string $code Locale code
     * @param string $languageCode Code of its language
     * @param string $countryCode Code of its country
     * @param bool $enabled Whether the locale is switched on
     * @return int Locale row id
     * @throws DatabaseException When a fixture statement fails
     */
    private function locale(string $code, string $languageCode, string $countryCode, bool $enabled): int
    {
        $found = Database::sql('SELECT `id`, `enabled` FROM `hilos_locale` WHERE `code` = ?', [$code])->row();
        if ($found !== null) {
            $this->foundLocaleSwitches[(int)$found['id']] = (int)$found['enabled'];
            Database::sqlRun('UPDATE `hilos_locale` SET `enabled` = ? WHERE `id` = ?', [(int)$enabled, (int)$found['id']]);

            return (int)$found['id'];
        }

        Database::sqlRun(
            'INSERT INTO `hilos_locale` (`code`, `language_id`, `country_id`, `date_format`, `time_format`, `number_format`,'
            . ' `phone_format`, `address_format`, `measurement_system`, `collation`, `enabled`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $code, $this->language($languageCode), $this->country($countryCode), 'DD.MM.YYYY', 'HH:mm:ss', '1,000.00',
                '+X (XXX) XXX-XXXX', 'House, Street, State, Index', 'metric', 'und', (int)$enabled,
            ],
        );
        $id = (int)Database::sql('SELECT `id` FROM `hilos_locale` WHERE `code` = ?', [$code])->field('id');
        $this->insertedLocales[] = $id;

        return $id;
    }

    /**
     * @param string $code Language code
     * @return int Language row id, inserted switched off when absent
     * @throws DatabaseException When a fixture statement fails
     */
    private function language(string $code): int
    {
        $found = Database::sql('SELECT `id` FROM `hilos_language` WHERE `code` = ?', [$code])->row();
        if ($found !== null) {
            return (int)$found['id'];
        }
        Database::sqlRun('INSERT INTO `hilos_language` (`code`, `native_name`) VALUES (?, ?)', [$code, 'HIL-2318 ' . $code]);
        $id = (int)Database::sql('SELECT `id` FROM `hilos_language` WHERE `code` = ?', [$code])->field('id');
        $this->insertedLanguages[] = $id;

        return $id;
    }

    /**
     * @param string $code Country code
     * @return int Country row id, inserted switched off when absent
     * @throws DatabaseException When a fixture statement fails
     */
    private function country(string $code): int
    {
        $found = Database::sql('SELECT `id` FROM `hilos_country` WHERE `code` = ?', [$code])->row();
        if ($found !== null) {
            return (int)$found['id'];
        }
        Database::sqlRun(
            'INSERT INTO `hilos_country` (`code`, `currency_symbol`, `currency_code`) VALUES (?, ?, ?)',
            [$code, '¤', 'XXX'],
        );
        $id = (int)Database::sql('SELECT `id` FROM `hilos_country` WHERE `code` = ?', [$code])->field('id');
        $this->insertedCountries[] = $id;

        return $id;
    }

    /**
     * @param int $id Locale row id
     * @return int Its stored first weekday
     * @throws DatabaseException When the row cannot be read
     */
    private function firstWeekday(int $id): int
    {
        return (int)Database::sql('SELECT `first_weekday` FROM `hilos_locale` WHERE `id` = ?', [$id])->field('first_weekday');
    }
}
