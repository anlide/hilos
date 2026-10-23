<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

/** Current installation language counts and its default language. */
final readonly class LanguageOverviewTally
{
    public const string ENABLED_COUNT = 'enabledCount';
    public const string TOTAL_COUNT = 'totalCount';
    public const string DEFAULT_LANGUAGE_CODE = 'defaultLanguageCode';
    public const string DEFAULT_LANGUAGE_NAME = 'defaultLanguageName';

    /**
     * @param int $enabledCount Switched-on language count
     * @param int $totalCount All installed languages
     * @param string $defaultLanguageCode Configured default language code
     * @param string $defaultLanguageName Current default row's native name
     */
    public function __construct(
        public int $enabledCount,
        public int $totalCount,
        public string $defaultLanguageCode,
        public string $defaultLanguageName,
    ) {
    }

    /**
     * @return array{enabledCount: int, totalCount: int, defaultLanguageCode: string, defaultLanguageName: string} Browser fields
     */
    public function toArray(): array
    {
        return [
            self::ENABLED_COUNT => $this->enabledCount,
            self::TOTAL_COUNT => $this->totalCount,
            self::DEFAULT_LANGUAGE_CODE => $this->defaultLanguageCode,
            self::DEFAULT_LANGUAGE_NAME => $this->defaultLanguageName,
        ];
    }
}
