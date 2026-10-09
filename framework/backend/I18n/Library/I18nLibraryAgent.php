<?php

declare(strict_types=1);

namespace Hilos\I18n\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DefaultLanguage;
use Hilos\I18n\MeasurementSystem;
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

            $localeDefinition = BuiltInI18nCatalog::locale($definition->code);
            if ($localeDefinition !== null) {
                $locale = Hilos::$db->locales[$definition->code]
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
