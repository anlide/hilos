<?php

declare(strict_types=1);

namespace Hilos\I18n\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\CliCommands;
use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\View\Item\Language;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DefaultLanguage;
use Hilos\I18n\I18nTestCommandConstants;
use Hilos\I18n\MeasurementSystem;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\WiringRefusal;
use JsonException;
use LogicException as NativeLogicException;
use Random\RandomException;
use Throwable;

/** Cluster library that serves i18n section pages and owns its five reference tables and the reflow record. */
final class I18nLibraryAgent extends AbstractHilosAgent
{
    /** @var array<string, list<TruthSourceOperation>> Whole-table write claims */
    public const array OWNS_DB = [
        HilosDbContext::languages => TruthSourceOperation::ALL,
        HilosDbContext::countries => TruthSourceOperation::ALL,
        HilosDbContext::locales => TruthSourceOperation::ALL,
        HilosDbContext::languageNames => TruthSourceOperation::ALL,
        HilosDbContext::countryNames => TruthSourceOperation::ALL,
        HilosDbContext::i18nReflows => TruthSourceOperation::ALL,
    ];

    public const string AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;

    /** The test-only i18n setup is answered here because this library owns the rows of its tables. */
    public const array AGENT_COMMANDS = [
        CliCommands::I18N_TEST_LANGUAGE_ON,
        CliCommands::I18N_TEST_LANGUAGE_OFF,
        CliCommands::I18N_TEST_LANGUAGE_DELETE,
        CliCommands::I18N_TEST_LOCALE_DELETE,
        CliCommands::I18N_TEST_LOCALE_ON,
        CliCommands::I18N_TEST_COUNTRY_OFF,
        CliCommands::I18N_TEST_COUNTRY_ON,
        CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED,
        CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR,
    ];

    private const string LANGUAGE_NAMES_TARGET_CODE = 'qv';
    private const string LANGUAGE_NAMES_WRITING_CODE = 'qw';

    /** @var array<string, string> Fixed fixture addresses and their ownership markers */
    private const array LANGUAGE_NAMES_MARKERS = [
        self::LANGUAGE_NAMES_TARGET_CODE => 'HIL-1490 target',
        self::LANGUAGE_NAMES_WRITING_CODE => 'HIL-1490 writing',
    ];

    /** @var array<string, string> Existing country codes and literal correction values */
    private const array LANGUAGE_NAMES_CORRECTIONS = ['gb' => 'Fixture correction', 'us' => ''];

    /**
     * @param CommandRequestDTO $data Command request
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the request names the command)
     * @throws HilosException When the command reply cannot be sent
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case CliCommands::I18N_TEST_LANGUAGE_ON:
                $this->handleLanguageOnCommand($data);
                return;
            case CliCommands::I18N_TEST_LANGUAGE_OFF:
                $this->handleLanguageOffCommand($data);
                return;
            case CliCommands::I18N_TEST_LANGUAGE_DELETE:
                $this->handleLanguageDeleteCommand($data);
                return;
            case CliCommands::I18N_TEST_LOCALE_DELETE:
                $this->handleLocaleDeleteCommand($data);
                return;
            case CliCommands::I18N_TEST_LOCALE_ON:
                $this->handleLocaleOnCommand($data);
                return;
            case CliCommands::I18N_TEST_COUNTRY_OFF:
                $this->handleCountryOffCommand($data);
                return;
            case CliCommands::I18N_TEST_COUNTRY_ON:
                $this->handleCountryOnCommand($data);
                return;
            case CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED:
                $this->handleLanguageNamesSeedCommand($data);
                return;
            case CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR:
                $this->handleLanguageNamesClearCommand($data);
                return;
            default:
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
        }
    }

    /**
     * Creates and enables the configured default language and its countryless locale, then takes
     * the built-in catalog in when its fingerprint differs from the one recorded (HIL-1472).
     *
     * The two are separate transactions: the default language is what the node promises, and a
     * failed reflow must not take it back. A default language created on this start gets the
     * catalog's country names in it inside its own transaction.
     *
     * @throws HilosException When configuration, ownership or persistence refuses provision or the reflow
     * @throws NativeLogicException When the inherited agent startup rejects its state
     * @throws RandomException When inherited startup cannot draw secure entropy
     */
    public function onStart(): void
    {
        parent::onStart();
        $definition = DefaultLanguage::definition();
        Database::transactionStart();
        try {
            $language = Hilos::$db->languages[$definition->code];
            $created = $language === null;
            $language ??= Hilos::$db->languages->actions->create(
                $definition->code,
                $definition->nativeName,
                $definition->rtl,
            );
            $language->actions->switchOn();

            $this->switchOnCatalogCountrylessLocale($language);
            if ($created) {
                Hilos::$db->countryNames->actions->takeAllFromCatalog($language);
            }

            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollback();
            throw $failure;
        }

        $this->reflowIfChanged();
    }

    /**
     * @param CommandRequestDTO $data Request with no arguments
     * @throws HilosException When wiring refuses access or the reply cannot be sent
     */
    private function handleLanguageNamesSeedCommand(CommandRequestDTO $data): void
    {
        try {
            if ($data->payload !== []) {
                throw new ValidationException('The language names fixture command takes no arguments.');
            }
            $this->seedLanguageNamesFixture();
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId));
    }

    /**
     * Every row is created together; repeat setup requires explicit cleanup first.
     *
     * @throws ValidationException When a fixture address is occupied or a required country is missing
     * @throws HilosException When ownership, persistence or rollback fails
     */
    private function seedLanguageNamesFixture(): void
    {
        $this->assertLanguageNamesFixtureMarkers();
        foreach (array_keys(self::LANGUAGE_NAMES_MARKERS) as $code) {
            if (Hilos::$db->languages[$code] !== null) {
                throw new ValidationException('The language names fixture already exists. Clear it before seeding again.');
            }
        }

        Database::transactionStart();
        try {
            $language = Hilos::$db->languages->actions->create(
                self::LANGUAGE_NAMES_TARGET_CODE,
                self::LANGUAGE_NAMES_MARKERS[self::LANGUAGE_NAMES_TARGET_CODE],
                false,
            );
            $inLanguage = Hilos::$db->languages->actions->create(
                self::LANGUAGE_NAMES_WRITING_CODE,
                self::LANGUAGE_NAMES_MARKERS[self::LANGUAGE_NAMES_WRITING_CODE],
                false,
            );
            Hilos::$db->languageNames->actions->createManual($language, $inLanguage, null, 'Fixture base');
            foreach (self::LANGUAGE_NAMES_CORRECTIONS as $countryCode => $correction) {
                $country = Hilos::$db->countries[$countryCode]
                    ?? throw new ValidationException('Missing fixture country: ' . $countryCode);
                $locale = Hilos::$db->locales->actions->create(
                    $inLanguage, $country, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
                    'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
                );
                Hilos::$db->languageNames->actions->createManual($language, $inLanguage, $locale, $correction);
            }
            Database::transactionCommit();
        } catch (Throwable $failure) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Preserve the failure that prevented the fixture from being created.
            }
            throw $failure;
        }
    }

    /**
     * Cleanup is resumable and opens no transaction around the base name's own cascade.
     * Both markers are checked before the first write; only fixed fixture coordinates are touched.
     *
     * @param CommandRequestDTO $data Request with no arguments
     * @throws HilosException When wiring refuses access or the reply cannot be sent
     */
    private function handleLanguageNamesClearCommand(CommandRequestDTO $data): void
    {
        try {
            if ($data->payload !== []) {
                throw new ValidationException('The language names fixture command takes no arguments.');
            }
            $this->assertLanguageNamesFixtureMarkers();
            $language = Hilos::$db->languages[self::LANGUAGE_NAMES_TARGET_CODE];
            $inLanguage = Hilos::$db->languages[self::LANGUAGE_NAMES_WRITING_CODE];
            if ($language !== null && $inLanguage !== null) {
                Hilos::$db->languageNames->findBase($language->id, $inLanguage->id)?->actions->delete();
            }
            foreach (array_keys(self::LANGUAGE_NAMES_CORRECTIONS) as $countryCode) {
                $localeCode = self::LANGUAGE_NAMES_WRITING_CODE . '-' . strtoupper($countryCode);
                Hilos::$db->locales[$localeCode]?->actions->switchOff();
                Hilos::$db->locales[$localeCode]?->actions->delete();
            }
            foreach (array_keys(self::LANGUAGE_NAMES_MARKERS) as $code) {
                Hilos::$db->languages[$code]?->actions->delete();
            }
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId));
    }

    /**
     * @throws ValidationException When another row occupies either fixture code
     * @throws HilosException When the marker lookup fails
     */
    private function assertLanguageNamesFixtureMarkers(): void
    {
        foreach (self::LANGUAGE_NAMES_MARKERS as $code => $marker) {
            if (Hilos::$db->languages[$code] !== null && Hilos::$db->languages[$code]->nativeName !== $marker) {
                throw new ValidationException("Language '{$code}' is not the language names fixture.");
            }
        }
    }

    /**
     * @param CommandRequestDTO $data Request carrying a built-in language code
     * @throws HilosException When the command reply cannot be sent
     */
    private function handleLanguageOnCommand(CommandRequestDTO $data): void
    {
        $code = $data->payload[I18nTestCommandConstants::FIELD_CODE] ?? null;
        if (!is_string($code)) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Language code must be a string'));

            return;
        }
        $definition = BuiltInI18nCatalog::language($code);
        if ($definition === null) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                "Unknown built-in language code '{$code}'",
            ));

            return;
        }

        try {
            Database::transactionStart();
            $language = Hilos::$db->languages[$definition->code]
                ?? Hilos::$db->languages->actions->create($definition->code, $definition->nativeName, $definition->rtl);
            $language->actions->switchOn();
            Database::transactionCommit();
        } catch (WiringRefusal $refusal) {
            Database::transactionRollback();
            throw $refusal;
        } catch (Throwable $failure) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Preserve the failure that prevented the command.
            }
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            I18nTestCommandConstants::FIELD_CODE => $definition->code,
            I18nTestCommandConstants::FIELD_ENABLED => true,
        ]));
    }

    /**
     * @param CommandRequestDTO $data Request carrying a built-in language code
     * @throws HilosException When the command reply cannot be sent
     */
    private function handleLanguageOffCommand(CommandRequestDTO $data): void
    {
        $code = $data->payload[I18nTestCommandConstants::FIELD_CODE] ?? null;
        if (!is_string($code)) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Language code must be a string'));
            return;
        }
        $definition = BuiltInI18nCatalog::language($code);
        if ($definition === null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown built-in language code '{$code}'"));
            return;
        }

        try {
            Database::transactionStart();
            $language = Hilos::$db->languages[$definition->code]
                ?? Hilos::$db->languages->actions->create($definition->code, $definition->nativeName, $definition->rtl);
            $language->actions->switchOff();
            Database::transactionCommit();
        } catch (WiringRefusal $refusal) {
            Database::transactionRollback();
            throw $refusal;
        } catch (Throwable $failure) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Preserve the failure that prevented the command.
            }
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));
            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            I18nTestCommandConstants::FIELD_CODE => $definition->code,
            I18nTestCommandConstants::FIELD_ENABLED => false,
        ]));
    }

    /**
     * The item action removes the fixture and its catalog country names in its own transaction.
     * An absent fixture is already clean, so teardown may follow a failed setup too.
     *
     * @param CommandRequestDTO $data Request carrying a built-in language code
     * @throws HilosException When wiring refuses the read or the command reply cannot be sent
     */
    private function handleLanguageDeleteCommand(CommandRequestDTO $data): void
    {
        $code = $data->payload[I18nTestCommandConstants::FIELD_CODE] ?? null;
        if (!is_string($code) || BuiltInI18nCatalog::language($code) === null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'A built-in language code is required'));

            return;
        }

        try {
            Hilos::$db->languages[$code]?->actions->delete();
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            I18nTestCommandConstants::FIELD_CODE => $code,
        ]));
    }

    /**
     * Cleanup is limited to a non-default built-in countryless locale; an absent fixture is clean.
     *
     * @param CommandRequestDTO $data Request carrying the fixture's locale code
     * @throws HilosException When wiring refuses the read or the command reply cannot be sent
     */
    private function handleLocaleDeleteCommand(CommandRequestDTO $data): void
    {
        $code = $data->payload[I18nTestCommandConstants::FIELD_CODE] ?? null;
        if (!is_string($code) || preg_match('/^[a-z]{2}$/D', $code) !== 1 || BuiltInI18nCatalog::locale($code) === null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'A built-in countryless locale code is required'));

            return;
        }

        try {
            if ($code === DefaultLanguage::code()) {
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'The default locale cannot be removed by test cleanup'));

                return;
            }
            Database::transactionStart();
            Hilos::$db->locales[$code]?->actions->switchOff();
            Hilos::$db->locales[$code]?->actions->delete();
            Database::transactionCommit();
        } catch (WiringRefusal $refusal) {
            Database::transactionRollback();
            throw $refusal;
        } catch (Throwable $failure) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Preserve the failure that prevented cleanup.
            }
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            I18nTestCommandConstants::FIELD_CODE => $code,
        ]));
    }

    /**
     * @param CommandRequestDTO $data Request carrying a countryless locale code
     * @throws HilosException When the command reply cannot be sent
     */
    private function handleLocaleOnCommand(CommandRequestDTO $data): void
    {
        $code = $data->payload[I18nTestCommandConstants::FIELD_CODE] ?? null;
        if (!is_string($code)) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Locale code must be a string'));
            return;
        }
        try {
            $language = Hilos::$db->languages[$code];
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));
            return;
        }
        if ($language === null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Language '{$code}' does not exist"));
            return;
        }
        if (BuiltInI18nCatalog::locale($code) === null) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                "The built-in catalog has no locale without a country for '{$code}'",
            ));
            return;
        }

        try {
            Database::transactionStart();
            $this->switchOnCatalogCountrylessLocale($language);
            Database::transactionCommit();
        } catch (WiringRefusal $refusal) {
            Database::transactionRollback();
            throw $refusal;
        } catch (Throwable $failure) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Preserve the failure that prevented the command.
            }
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));
            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            I18nTestCommandConstants::FIELD_CODE => $code,
            I18nTestCommandConstants::FIELD_ENABLED => true,
        ]));
    }

    /**
     * @param CommandRequestDTO $data Request carrying a built-in country code
     * @throws HilosException When the command reply cannot be sent
     */
    private function handleCountryOnCommand(CommandRequestDTO $data): void
    {
        $this->switchBuiltInCountry($data, true);
    }

    /**
     * @param CommandRequestDTO $data Request carrying a built-in country code
     * @throws HilosException When the command reply cannot be sent
     */
    private function handleCountryOffCommand(CommandRequestDTO $data): void
    {
        $this->switchBuiltInCountry($data, false);
    }

    /**
     * @param CommandRequestDTO $data Request carrying a built-in country code
     * @param bool $enabled Target enabled state
     * @throws HilosException When the command reply cannot be sent
     */
    private function switchBuiltInCountry(CommandRequestDTO $data, bool $enabled): void
    {
        $code = $data->payload[I18nTestCommandConstants::FIELD_CODE] ?? null;
        if (!is_string($code)) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Country code must be a string'));

            return;
        }
        $definition = BuiltInI18nCatalog::country($code);
        if ($definition === null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown built-in country code '{$code}'"));

            return;
        }

        try {
            Database::transactionStart();
            $country = Hilos::$db->countries[$definition->code]
                ?? Hilos::$db->countries->actions->create(
                    $definition->code,
                    $definition->currencySymbol,
                    $definition->currencyCode,
                );
            if ($enabled) {
                $country->actions->switchOn();
            } else {
                $country->actions->switchOff();
            }
            Database::transactionCommit();
        } catch (WiringRefusal $refusal) {
            Database::transactionRollback();
            throw $refusal;
        } catch (Throwable $failure) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Preserve the failure that prevented the command.
            }
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            I18nTestCommandConstants::FIELD_CODE => $definition->code,
            I18nTestCommandConstants::FIELD_ENABLED => $enabled,
        ]));
    }

    /** @param Language $language Language whose catalog locale may be provisioned */
    private function switchOnCatalogCountrylessLocale(Language $language): void
    {
        $localeDefinition = BuiltInI18nCatalog::locale($language->code);
        if ($localeDefinition === null) {
            return;
        }

        $locale = Hilos::$db->locales[$language->code]
            ?? Hilos::$db->locales->actions->create(
                $language,
                null,
                $localeDefinition->dateFormat,
                $localeDefinition->timeFormat,
                $localeDefinition->numberFormat,
                $localeDefinition->phoneFormat,
                $localeDefinition->addressFormat,
                MeasurementSystem::from($localeDefinition->measurementSystem),
                $localeDefinition->collation,
            );
        $locale->actions->switchOn();
    }

    /**
     * Takes the built-in catalog into the reference tables when its fingerprint differs from the
     * recorded one - a fresh installation, a new or an older framework, a restored archive - and
     * records the new fingerprint as the last step. An equal fingerprint writes nothing at all.
     *
     * One transaction: a failure rolls the whole reflow back with the record, so the next start
     * runs it again. The walk is over the catalog, so a row of the installation's own is never
     * reached; a row that is switched on and a name someone locked are left as they are.
     *
     * @throws LogicException When the built-in catalog cannot be fingerprinted
     * @throws HilosException When ownership, a write door or the database refuses the reflow
     */
    private function reflowIfChanged(): void
    {
        try {
            $fingerprint = BuiltInI18nCatalog::fingerprint();
        } catch (JsonException $unencodable) {
            throw new LogicException('The built-in i18n catalog cannot be fingerprinted', 0, $unencodable);
        }
        if (Hilos::$db->i18nReflows->recordedFingerprint() === $fingerprint) {
            return;
        }

        Database::transactionStart();
        try {
            Hilos::$db->countries->actions->takeFromCatalog();
            Hilos::$db->languages->actions->refreshFromCatalog();
            Hilos::$db->locales->actions->refreshFromCatalog();
            foreach (Hilos::$db->languages as $language) {
                Hilos::$db->countryNames->actions->takeAllFromCatalog($language);
            }
            Hilos::$db->i18nReflows->actions->record($fingerprint);
            Database::transactionCommit();
        } catch (Throwable $failure) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Keep the failure that stopped the reflow.
            }
            throw $failure;
        }
    }
}
