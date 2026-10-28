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
use Hilos\Database\View\Item\LanguageName;
use Hilos\Database\View\Item\CountryName;
use Hilos\Database\View\Item\Locale;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DefaultLanguage;
use Hilos\I18n\DTO\I18nTestLanguageCreateDTO;
use Hilos\I18n\DTO\I18nTestCountryCreateDTO;
use Hilos\I18n\DTO\I18nTestLocaleCreateDTO;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\I18nTestCommandConstants;
use Hilos\I18n\LocaleTemplates;
use Hilos\I18n\MeasurementSystem;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Logger;
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
        CliCommands::I18N_TEST_LANGUAGE_CREATE,
        CliCommands::I18N_TEST_COUNTRY_CREATE,
        CliCommands::I18N_TEST_LOCALE_CREATE,
        CliCommands::I18N_TEST_LANGUAGE_CLEAR,
        CliCommands::I18N_TEST_COUNTRY_CLEAR,
        CliCommands::I18N_TEST_LOCALE_CLEAR,
        CliCommands::I18N_TEST_LANGUAGE_ON,
        CliCommands::I18N_TEST_LANGUAGE_OFF,
        CliCommands::I18N_TEST_LANGUAGE_DELETE,
        CliCommands::I18N_TEST_LOCALE_DELETE,
        CliCommands::I18N_TEST_LOCALE_ON,
        CliCommands::I18N_TEST_COUNTRY_OFF,
        CliCommands::I18N_TEST_COUNTRY_ON,
        CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED,
        CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR,
        CliCommands::I18N_TEST_COUNTRY_NAMES_SEED,
        CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR,
    ];

    private const string LANGUAGE_NAMES_TARGET_CODE = 'qv';
    private const string LANGUAGE_NAMES_WRITING_CODE = 'qw';
    private const string LANGUAGE_NAMES_REVERSE_COUNTRY_CODE = 'gb';

    /** @var array<string, string> Fixed fixture addresses and their ownership markers */
    private const array LANGUAGE_NAMES_MARKERS = [
        self::LANGUAGE_NAMES_TARGET_CODE => 'HIL-1490 target',
        self::LANGUAGE_NAMES_WRITING_CODE => 'HIL-1490 writing',
    ];

    /** @var array<string, string> Existing country codes and literal correction values */
    private const array LANGUAGE_NAMES_CORRECTIONS = ['gb' => 'Fixture correction', 'us' => ''];

    private const string COUNTRY_NAMES_TARGET_CODE = 'qj';
    private const string COUNTRY_NAMES_WRITING_CODE = 'qk';
    private const string COUNTRY_NAMES_CURRENCY_SYMBOL = 'H1501';
    private const string COUNTRY_NAMES_CURRENCY_CODE = 'QJJ';
    private const string COUNTRY_NAMES_WRITING_MARKER = 'HIL-1501 writing';
    private const string COUNTRY_NAMES_ADD_LOCALE_COUNTRY = 'fr';
    private const string COUNTRY_NAMES_ADD_LANGUAGE = 'en';

    /** @var array<string, string> Existing country codes and literal correction values */
    private const array COUNTRY_NAMES_CORRECTIONS = ['gb' => 'Fixture correction', 'us' => ''];

    /**
     * @param CommandRequestDTO $data Command request
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the request names the command)
     * @throws HilosException When the command reply cannot be sent
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case CliCommands::I18N_TEST_LANGUAGE_CREATE:
                $this->handleLanguageCreateCommand($data);
                return;
            case CliCommands::I18N_TEST_COUNTRY_CREATE:
                $this->handleCountryCreateCommand($data);
                return;
            case CliCommands::I18N_TEST_LOCALE_CREATE:
                $this->handleLocaleCreateCommand($data);
                return;
            case CliCommands::I18N_TEST_LANGUAGE_CLEAR:
            case CliCommands::I18N_TEST_COUNTRY_CLEAR:
            case CliCommands::I18N_TEST_LOCALE_CLEAR:
                $this->handleFixtureClearCommand($data);
                return;
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
            case CliCommands::I18N_TEST_COUNTRY_NAMES_SEED:
                $this->handleCountryNamesSeedCommand($data);
                return;
            case CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR:
                $this->handleCountryNamesClearCommand($data);
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
            $created = !isset(Hilos::$db->languages[$definition->code]);
            if ($created) {
                $language = Hilos::$db->languages->actions->create(
                    $definition->code,
                    $definition->nativeName,
                    $definition->rtl,
                );
            } else {
                $language = Hilos::$db->languages[$definition->code];
            }
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
    private function handleCountryNamesSeedCommand(CommandRequestDTO $data): void
    {
        try {
            if ($data->payload !== []) {
                throw new ValidationException('The country names fixture command takes no arguments.');
            }
            $this->seedCountryNamesFixture();
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId));
    }

    /**
     * @throws ValidationException When a fixture address is occupied or a required country is missing
     * @throws HilosException When ownership or persistence refuses the fixture
     */
    private function seedCountryNamesFixture(): void
    {
        if (isset(Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE])
            || isset(Hilos::$db->languages[self::COUNTRY_NAMES_WRITING_CODE])) {
            throw new ValidationException('The country names fixture already exists. Clear it before seeding again.');
        }

        Database::transactionStart();
        try {
            $country = Hilos::$db->countries->actions->create(
                self::COUNTRY_NAMES_TARGET_CODE,
                self::COUNTRY_NAMES_CURRENCY_SYMBOL,
                self::COUNTRY_NAMES_CURRENCY_CODE,
            );
            $language = Hilos::$db->languages->actions->create(
                self::COUNTRY_NAMES_WRITING_CODE,
                self::COUNTRY_NAMES_WRITING_MARKER,
                false,
            );
            Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Fixture base');
            foreach (self::COUNTRY_NAMES_CORRECTIONS as $countryCode => $correction) {
                $localeCountry = Hilos::$db->countries[$countryCode]
                    ?? throw new ValidationException('Missing fixture country: ' . $countryCode);
                $locale = Hilos::$db->locales->actions->create(
                    $language, $localeCountry, LocaleTemplates::DATE[0], LocaleTemplates::TIME[0],
                    LocaleTemplates::NUMBER[0], LocaleTemplates::PHONE[0], LocaleTemplates::ADDRESS[0],
                    MeasurementSystem::METRIC, LocaleTemplates::COLLATION[0],
                );
                Hilos::$db->countryNames->actions->createManual($country, $language, $locale, $correction);
            }
            Hilos::$db->locales->actions->create(
                $language,
                Hilos::$db->countries[self::COUNTRY_NAMES_ADD_LOCALE_COUNTRY]
                    ?? throw new ValidationException('Missing fixture country: ' . self::COUNTRY_NAMES_ADD_LOCALE_COUNTRY),
                LocaleTemplates::DATE[0], LocaleTemplates::TIME[0], LocaleTemplates::NUMBER[0],
                LocaleTemplates::PHONE[0], LocaleTemplates::ADDRESS[0], MeasurementSystem::METRIC, LocaleTemplates::COLLATION[0],
            );
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
    }

    /**
     * Checks both markers before writing and resumes cleanup through the ordinary item guards.
     * No outer transaction encloses the base name's own cascade.
     *
     * @param CommandRequestDTO $data Request with no arguments
     * @throws HilosException When wiring refuses access or the reply cannot be sent
     */
    private function handleCountryNamesClearCommand(CommandRequestDTO $data): void
    {
        try {
            if ($data->payload !== []) {
                throw new ValidationException('The country names fixture command takes no arguments.');
            }
            $this->assertCountryNamesFixtureMarkers();
            if (isset(Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE])
                && isset(Hilos::$db->languages[self::COUNTRY_NAMES_ADD_LANGUAGE])) {
                Hilos::$db->countryNames->findBase(
                    Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE]->id,
                    Hilos::$db->languages[self::COUNTRY_NAMES_ADD_LANGUAGE]->id,
                )?->actions->delete();
            }
            if (isset(Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE])
                && isset(Hilos::$db->languages[self::COUNTRY_NAMES_WRITING_CODE])) {
                Hilos::$db->countryNames->findBase(
                    Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE]->id,
                    Hilos::$db->languages[self::COUNTRY_NAMES_WRITING_CODE]->id,
                )?->actions->delete();
            }
            foreach ([...array_keys(self::COUNTRY_NAMES_CORRECTIONS), self::COUNTRY_NAMES_ADD_LOCALE_COUNTRY] as $countryCode) {
                if (isset(Hilos::$db->locales[self::COUNTRY_NAMES_WRITING_CODE . '-' . strtoupper($countryCode)])) {
                    Hilos::$db->locales[self::COUNTRY_NAMES_WRITING_CODE . '-' . strtoupper($countryCode)]->actions->delete();
                }
            }
            if (isset(Hilos::$db->languages[self::COUNTRY_NAMES_WRITING_CODE])) {
                Hilos::$db->languages[self::COUNTRY_NAMES_WRITING_CODE]->actions->delete();
            }
            if (isset(Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE])) {
                Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE]->actions->delete();
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
    private function assertCountryNamesFixtureMarkers(): void
    {
        if (isset(Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE])
            && (Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE]->currencySymbol !== self::COUNTRY_NAMES_CURRENCY_SYMBOL
                || Hilos::$db->countries[self::COUNTRY_NAMES_TARGET_CODE]->currencyCode !== self::COUNTRY_NAMES_CURRENCY_CODE)) {
            throw new ValidationException('Country qj is not the country names fixture.');
        }
        if (isset(Hilos::$db->languages[self::COUNTRY_NAMES_WRITING_CODE])
            && (Hilos::$db->languages[self::COUNTRY_NAMES_WRITING_CODE]->nativeName !== self::COUNTRY_NAMES_WRITING_MARKER
                || Hilos::$db->languages[self::COUNTRY_NAMES_WRITING_CODE]->rtl)) {
            throw new ValidationException('Language qk is not the country names fixture.');
        }
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
            if (isset(Hilos::$db->languages[$code])) {
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
            Hilos::$db->locales->actions->create(
                $language,
                Hilos::$db->countries[self::LANGUAGE_NAMES_REVERSE_COUNTRY_CODE]
                    ?? throw new ValidationException('Missing fixture country: ' . self::LANGUAGE_NAMES_REVERSE_COUNTRY_CODE),
                'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
                'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
            );
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
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
            if (isset(Hilos::$db->languages[self::LANGUAGE_NAMES_TARGET_CODE])
                && isset(Hilos::$db->languages[self::LANGUAGE_NAMES_WRITING_CODE])) {
                $language = Hilos::$db->languages[self::LANGUAGE_NAMES_TARGET_CODE];
                $inLanguage = Hilos::$db->languages[self::LANGUAGE_NAMES_WRITING_CODE];
                Hilos::$db->languageNames->findBase($language->id, $inLanguage->id)?->actions->delete();
                Hilos::$db->languageNames->findBase($inLanguage->id, $language->id)?->actions->delete();
            }
            foreach (array_keys(self::LANGUAGE_NAMES_CORRECTIONS) as $countryCode) {
                $localeCode = self::LANGUAGE_NAMES_WRITING_CODE . '-' . strtoupper($countryCode);
                if (isset(Hilos::$db->locales[$localeCode])) {
                    Hilos::$db->locales[$localeCode]->actions->switchOff();
                }
                if (isset(Hilos::$db->locales[$localeCode])) {
                    Hilos::$db->locales[$localeCode]->actions->delete();
                }
            }
            $reverseLocaleCode = self::LANGUAGE_NAMES_TARGET_CODE . '-' . strtoupper(self::LANGUAGE_NAMES_REVERSE_COUNTRY_CODE);
            if (isset(Hilos::$db->locales[$reverseLocaleCode])) {
                Hilos::$db->locales[$reverseLocaleCode]->actions->switchOff();
            }
            if (isset(Hilos::$db->locales[$reverseLocaleCode])) {
                Hilos::$db->locales[$reverseLocaleCode]->actions->delete();
            }
            foreach (array_keys(self::LANGUAGE_NAMES_MARKERS) as $code) {
                if (isset(Hilos::$db->languages[$code])) {
                    Hilos::$db->languages[$code]->actions->delete();
                }
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
            if (isset(Hilos::$db->languages[$code]) && Hilos::$db->languages[$code]->nativeName !== $marker) {
                throw new ValidationException("Language '{$code}' is not the language names fixture.");
            }
        }
    }

    /**
     * @param CommandRequestDTO $data Request to create one language fixture
     * @throws HilosException When wiring refuses access or the reply cannot be sent
     */
    private function handleLanguageCreateCommand(CommandRequestDTO $data): void
    {
        try {
            $dto = I18nTestLanguageCreateDTO::fromArray($data->payload);
            Database::transactionStart();
            try {
                $language = Hilos::$db->languages->actions->create($dto->code, $dto->nativeName, $dto->rtl);
                Hilos::$db->countryNames->actions->takeAllFromCatalog($language);
                if ($dto->enabled) {
                    $language->actions->switchOn();
                }
                Database::transactionCommit();
            } catch (Throwable $failure) {
                Database::transactionRollbackSilent();
                throw $failure;
            }
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            I18nTestCommandConstants::FIELD_ID => $language->id,
            I18nTestCommandConstants::FIELD_CODE => $language->code,
            I18nTestCommandConstants::FIELD_ENABLED => $language->enabled,
        ]));
    }

    /**
     * @param CommandRequestDTO $data Request to create one country fixture
     * @throws HilosException When wiring refuses access or the reply cannot be sent
     */
    private function handleCountryCreateCommand(CommandRequestDTO $data): void
    {
        try {
            $dto = I18nTestCountryCreateDTO::fromArray($data->payload);
            Database::transactionStart();
            try {
                $country = Hilos::$db->countries->actions->create($dto->code, $dto->currencySymbol, $dto->currencyCode);
                if ($dto->enabled) {
                    $country->actions->switchOn();
                }
                Database::transactionCommit();
            } catch (Throwable $failure) {
                Database::transactionRollbackSilent();
                throw $failure;
            }
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            I18nTestCommandConstants::FIELD_ID => $country->id,
            I18nTestCommandConstants::FIELD_CODE => $country->code,
            I18nTestCommandConstants::FIELD_ENABLED => $country->enabled,
        ]));
    }

    /**
     * @param CommandRequestDTO $data Request to create one locale fixture
     * @throws HilosException When wiring refuses access or the reply cannot be sent
     */
    private function handleLocaleCreateCommand(CommandRequestDTO $data): void
    {
        try {
            $dto = I18nTestLocaleCreateDTO::fromArray($data->payload);
            Database::transactionStart();
            try {
                if (!isset(Hilos::$db->languages[$dto->languageId])) {
                    throw new ValidationException('Locale language does not exist');
                }
                if ($dto->countryId !== null && !isset(Hilos::$db->countries[$dto->countryId])) {
                    throw new ValidationException('Locale country does not exist');
                }
                $code = Hilos::$db->languages[$dto->languageId]->code;
                if ($dto->countryId !== null) {
                    $code .= '-' . strtoupper(Hilos::$db->countries[$dto->countryId]->code);
                }
                $definition = BuiltInI18nCatalog::locale($code);
                $formats = $definition === null
                    ? new LocaleFormats(
                        LocaleTemplates::DATE[0],
                        LocaleTemplates::TIME[0],
                        LocaleTemplates::NUMBER[0],
                        LocaleTemplates::PHONE[0],
                        LocaleTemplates::ADDRESS[0],
                        MeasurementSystem::METRIC,
                        LocaleTemplates::COLLATION[0],
                    )
                    : LocaleFormats::ofCatalog($definition);
                $locale = Hilos::$db->locales->actions->create(
                    Hilos::$db->languages[$dto->languageId],
                    Hilos::$db->countries[$dto->countryId] ?? null,
                    $formats->date,
                    $formats->time,
                    $formats->number,
                    $formats->phone,
                    $formats->address,
                    $formats->measurement,
                    $formats->collation,
                );
                if ($dto->enabled) {
                    $locale->actions->switchOn();
                }
                Database::transactionCommit();
            } catch (Throwable $failure) {
                Database::transactionRollbackSilent();
                throw $failure;
            }
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            I18nTestCommandConstants::FIELD_ID => $locale->id,
            I18nTestCommandConstants::FIELD_CODE => $locale->code,
            I18nTestCommandConstants::FIELD_ENABLED => $locale->enabled,
        ]));
    }

    /**
     * @param CommandRequestDTO $data Request with exactly one code string
     * @throws HilosException When wiring refuses access or the reply cannot be sent
     */
    private function handleFixtureClearCommand(CommandRequestDTO $data): void
    {
        try {
            $code = $data->payload[I18nTestCommandConstants::FIELD_CODE] ?? null;
            $pattern = $data->command === CliCommands::I18N_TEST_LOCALE_CLEAR ? '/^[a-z]{2}(?:-[A-Z]{2})?$/D' : '/^[a-z]{2}$/D';
            if (count($data->payload) !== 1 || !is_string($code) || preg_match($pattern, $code) !== 1) {
                throw new ValidationException('The fixture clear command requires exactly one valid code string.');
            }
            switch ($data->command) {
                case CliCommands::I18N_TEST_LANGUAGE_CLEAR:
                    if ($code === DefaultLanguage::code()) {
                        throw new ValidationException('The default language cannot be removed by test cleanup.');
                    }
                    if (isset(Hilos::$db->languages[$code])) {
                        $this->clearLanguageFixture(Hilos::$db->languages[$code]->id);
                    }
                    break;
                case CliCommands::I18N_TEST_COUNTRY_CLEAR:
                    if (BuiltInI18nCatalog::country($code) !== null) {
                        throw new ValidationException('A built-in country cannot be removed by test cleanup.');
                    }
                    if (isset(Hilos::$db->countries[$code])) {
                        $this->clearCountryFixture(Hilos::$db->countries[$code]->id);
                    }
                    break;
                case CliCommands::I18N_TEST_LOCALE_CLEAR:
                    if ($code === DefaultLanguage::code()) {
                        throw new ValidationException('The default locale cannot be removed by test cleanup.');
                    }
                    if (isset(Hilos::$db->locales[$code])) {
                        $this->clearLocaleFixture(Hilos::$db->locales[$code]->id);
                    }
                    break;
            }
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
     * Captures the dependent set before its base-name cascades change membership.
     * Each base and the language keep their own transaction; the graph has no outer one.
     *
     * @param int $languageId Captured fixture language primary id
     * @throws HilosException When lookup, ownership or a guarded action refuses cleanup
     */
    private function clearLanguageFixture(int $languageId): void
    {
        $languageNames = Hilos::$db->languageNames->filter(
            static fn (LanguageName $name): bool => $name->languageId === $languageId || $name->inLanguageId === $languageId,
        );
        foreach ($languageNames as $name) {
            if (isset(Hilos::$db->languageNames[$name->id])) {
                Hilos::$db->languageNames[$name->id]->actions->delete();
            }
        }
        $countryNames = Hilos::$db->countryNames->filter(static fn (CountryName $name): bool => $name->languageId === $languageId);
        foreach ($countryNames as $name) {
            if (isset(Hilos::$db->countryNames[$name->id])) {
                Hilos::$db->countryNames[$name->id]->actions->delete();
            }
        }
        $locales = Hilos::$db->locales->filter(static fn (Locale $locale): bool => $locale->languageId === $languageId);
        foreach ($locales as $locale) {
            $this->clearLocaleFixture($locale->id);
        }
        if (Hilos::$db->languages[$languageId]->enabled) {
            Hilos::$db->languages[$languageId]->actions->switchOff();
        }
        Hilos::$db->languages[$languageId]->actions->delete();
    }

    /**
     * Captures names before base cascades and locales before removing the country.
     *
     * @param int $countryId Captured fixture country primary id
     * @throws HilosException When lookup, ownership or a guarded action refuses cleanup
     */
    private function clearCountryFixture(int $countryId): void
    {
        $names = Hilos::$db->countryNames->filter(static fn (CountryName $name): bool => $name->countryId === $countryId);
        foreach ($names as $name) {
            if (isset(Hilos::$db->countryNames[$name->id])) {
                Hilos::$db->countryNames[$name->id]->actions->delete();
            }
        }
        $locales = Hilos::$db->locales->filter(static fn (Locale $locale): bool => $locale->countryId === $countryId);
        foreach ($locales as $locale) {
            $this->clearLocaleFixture($locale->id);
        }
        if (Hilos::$db->countries[$countryId]->enabled) {
            Hilos::$db->countries[$countryId]->actions->switchOff();
        }
        Hilos::$db->countries[$countryId]->actions->delete();
    }

    /**
     * Only a matching country default is cleared; the country's switch is restored atomically.
     *
     * @param int $localeId Captured fixture locale primary id
     * @throws HilosException When lookup, ownership or a guarded action refuses cleanup
     */
    private function clearLocaleFixture(int $localeId): void
    {
        foreach (Hilos::$db->languageNames->forLocaleId($localeId) as $name) {
            Hilos::$db->languageNames[$name->id]->actions->delete();
        }
        foreach (Hilos::$db->countryNames->forLocaleId($localeId) as $name) {
            Hilos::$db->countryNames[$name->id]->actions->delete();
        }
        $countryId = Hilos::$db->locales[$localeId]->countryId;
        if ($countryId !== null && Hilos::$db->countries[$countryId]->defaultLocaleId === $localeId) {
            $enabled = Hilos::$db->countries[$countryId]->enabled;
            Database::transactionStart();
            try {
                if ($enabled) {
                    Hilos::$db->countries[$countryId]->actions->switchOff();
                }
                Hilos::$db->countries[$countryId]->actions->update(
                    Hilos::$db->countries[$countryId]->currencySymbol,
                    Hilos::$db->countries[$countryId]->currencyCode,
                    null,
                );
                if ($enabled) {
                    Hilos::$db->countries[$countryId]->actions->switchOn();
                }
                Database::transactionCommit();
            } catch (Throwable $failure) {
                Database::transactionRollbackSilent();
                throw $failure;
            }
        }
        if (Hilos::$db->locales[$localeId]->enabled) {
            Hilos::$db->locales[$localeId]->actions->switchOff();
        }
        Hilos::$db->locales[$localeId]->actions->delete();
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
            Database::transactionRollbackSilent();
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
            Database::transactionRollbackSilent();
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
            if (isset(Hilos::$db->languages[$code])) {
                Hilos::$db->languages[$code]->actions->delete();
            }
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
            if (isset(Hilos::$db->locales[$code])) {
                Hilos::$db->locales[$code]->actions->switchOff();
            }
            if (isset(Hilos::$db->locales[$code])) {
                Hilos::$db->locales[$code]->actions->delete();
            }
            Database::transactionCommit();
        } catch (WiringRefusal $refusal) {
            Database::transactionRollback();
            throw $refusal;
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
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
            if (!isset(Hilos::$db->languages[$code])) {
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Language '{$code}' does not exist"));
                return;
            }
            $language = Hilos::$db->languages[$code];
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $failure->getMessage()));
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
            Database::transactionRollbackSilent();
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
            Database::transactionRollbackSilent();
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
     * Logs the fingerprint after the reflow transaction commits successfully.
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
            Database::transactionRollbackSilent();
            throw $failure;
        }

        Logger::info("I18n catalog reflow committed: {$fingerprint}");
    }
}
