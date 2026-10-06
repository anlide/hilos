<?php

declare(strict_types=1);

namespace Hilos\I18n\Catalog;

/** A language shipped in the framework's built-in catalog. */
final readonly class LanguageDefinition
{
    /**
     * @param string $code Lowercase language code
     * @param string $nativeName Name written in the language itself
     * @param bool $rtl Whether text runs right to left
     */
    public function __construct(
        public string $code,
        public string $nativeName,
        public bool $rtl,
    ) {
    }
}
