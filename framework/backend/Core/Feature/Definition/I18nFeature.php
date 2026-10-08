<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\Country;
use Hilos\Database\Entity\Item\CountryName;
use Hilos\Database\Entity\Item\I18nReflow;
use Hilos\Database\Entity\Item\Language;
use Hilos\Database\Entity\Item\LanguageName;
use Hilos\Database\Entity\Item\Locale;
use Hilos\Pages\AbstractHilosI18nPage;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nCountriesListPage;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nLanguagesListPage;

/** The framework's language and country section over one library of five reference tables and its reflow record. */
final class I18nFeature extends FeatureDefinition
{
    /** @return HilosFeature I18n feature case */
    public function feature(): HilosFeature
    {
        return HilosFeature::I18N;
    }

    /** @return FeatureRequirements Three section pages, one agent and six SQL tables */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [
                AbstractHilosI18nPage::class,
                AbstractHilosI18nLanguagesListPage::class,
                AbstractHilosI18nCountriesListPage::class,
            ],
            requiredAgents: [HilosAgentType::HILOS_I18N_LIBRARY],
            requiredDbTables: [
                Language::_table,
                Country::_table,
                Locale::_table,
                LanguageName::_table,
                CountryName::_table,
                I18nReflow::_table,
            ],
        );
    }
}
