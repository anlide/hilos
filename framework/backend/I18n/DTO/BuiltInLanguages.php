<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\Catalog\LanguageDefinition;

/** Built-in languages delivered in catalog order in the page scope. */
final readonly class BuiltInLanguages
{
    public const string DATA = 'builtInLanguages';
    public const string CODE = 'code';
    public const string NATIVE_NAME = 'nativeName';
    public const string RTL = 'rtl';

    /** @param list<LanguageDefinition> $languages Languages in catalog order */
    public function __construct(public array $languages)
    {
    }

    /** @return self Built-in language definitions */
    public static function fromCatalog(): self
    {
        return new self([...BuiltInI18nCatalog::languages()]);
    }

    /** @return list<array{code: string, nativeName: string, rtl: bool}> Languages in catalog order */
    public function toArray(): array
    {
        $languages = [];
        foreach ($this->languages as $language) {
            $languages[] = [
                self::CODE => $language->code,
                self::NATIVE_NAME => $language->nativeName,
                self::RTL => $language->rtl,
            ];
        }

        return $languages;
    }
}
