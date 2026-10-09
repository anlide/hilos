<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables;

use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * EcommerceShopTableContext - App-specific table context ($table layer) for this demo.
 *
 * Registers whatever the project topology lists: today the backup section's archive table, the
 * maintenance section's verifier circle table, the settings table and the people table, accessed
 * via Hilos::$table->hilosBackups, Hilos::$table->hilosVerifierCircle, Hilos::$table->settings and
 * Hilos::$table->hilosUsers. The other admin sections arrive with the leaves that move their e2e
 * onto this demo, each with its table.
 *
 * @property-read HilosBackupHistoryTable $hilosBackups
 * @property-read HilosVerifierCircleTable $hilosVerifierCircle
 * @property-read HilosSettingsTable $settings
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosI18nLanguageNamesTable $hilosI18nLanguageNames
 * @property-read HilosI18nLanguagesTable $hilosI18nLanguages
 * @property-read HilosI18nCountryNamesTable $hilosI18nCountryNames
 * @property-read HilosI18nLanguageLocalesTable $hilosI18nLanguageLocales
 */
final class EcommerceShopTableContext extends TableContext
{
    public const string hilosBackups = HilosBackupHistoryTable::TABLE;
    public const string hilosVerifierCircle = HilosVerifierCircleTable::TABLE;
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosI18nLanguageNames = HilosI18nLanguageNamesTable::TABLE;
    public const string hilosI18nLanguages = HilosI18nLanguagesTable::TABLE;
    public const string hilosI18nCountryNames = HilosI18nCountryNamesTable::TABLE;
    public const string hilosI18nLanguageLocales = HilosI18nLanguageLocalesTable::TABLE;

    /**
     * Registers this demo's table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}
