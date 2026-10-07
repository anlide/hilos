<?php

declare(strict_types=1);

namespace Hilos\I18n\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DefaultLanguage;
use Hilos\I18n\MeasurementSystem;
use LogicException as NativeLogicException;

/** Cluster library that serves i18n section pages and owns its five reference tables. */
final class I18nLibraryAgent extends AbstractHilosAgent
{
    /** @var array<string, list<TruthSourceOperation>> Whole-table write claims */
    public const array OWNS_DB = [
        HilosDbContext::languages => TruthSourceOperation::ALL,
        HilosDbContext::countries => TruthSourceOperation::ALL,
        HilosDbContext::locales => TruthSourceOperation::ALL,
        HilosDbContext::languageNames => TruthSourceOperation::ALL,
        HilosDbContext::countryNames => TruthSourceOperation::ALL,
    ];

    public const string AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;

    /**
     * Creates and enables the configured default language and its countryless locale.
     *
     * @throws HilosException When configuration, ownership or persistence refuses provision
     * @throws NativeLogicException When the inherited agent startup rejects its state
     */
    public function onStart(): void
    {
        parent::onStart();
        $definition = DefaultLanguage::definition();
        Database::transactionStart();
        try {
            $language = Hilos::$db->languages[$definition->code]
                ?? Hilos::$db->languages->actions->create(
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

            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollback();
            throw $failure;
        }
    }
}
