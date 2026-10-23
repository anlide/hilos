<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;

/** Complete read-only overview carried by the i18n root page. */
final readonly class I18nOverviewData
{
    public const string DATA = 'i18nOverview';
    public const string LANGUAGES = 'languages';
    public const string COUNTRIES = 'countries';

    /**
     * @param LanguageOverviewTally $languages Installed language facts
     * @param CountryOverviewTally $countries Installed country facts
     */
    public function __construct(
        public LanguageOverviewTally $languages,
        public CountryOverviewTally $countries,
    ) {
    }

    /**
     * @return self Complete overview of the current installation
     * @throws DatabaseException When reading the reference fails
     * @throws ItemNotFoundException When the required default language row is missing
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     * @throws EnvException When the default language environment key is absent
     * @throws EnvInvalidValueException When the configured default code is unknown
     */
    public static function fromCollections(): self
    {
        return new self(
            Hilos::$db->languages->overviewTally(),
            Hilos::$db->countries->overviewTally(),
        );
    }

    /**
     * @return array{
     *     languages: array{enabledCount: int, totalCount: int, defaultLanguageCode: string, defaultLanguageName: string},
     *     countries: array{enabledCount: int, totalCount: int, builtInCount: int, ownCount: int}
     * } Complete browser overview
     */
    public function toArray(): array
    {
        return [
            self::LANGUAGES => $this->languages->toArray(),
            self::COUNTRIES => $this->countries->toArray(),
        ];
    }
}
