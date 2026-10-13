<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

/** One locale offered by the country's edit form, including switched-off locales. */
final readonly class CountryLocaleChoice
{
    public const string code = 'code';
    public const string enabled = 'enabled';

    /**
     * @param string $code Locale code
     * @param bool $enabled Whether the locale is switched on
     */
    public function __construct(public string $code, public bool $enabled)
    {
    }

    /** @return array{code: string, enabled: bool} Locale choice on the wire */
    public function toArray(): array
    {
        return [self::code => $this->code, self::enabled => $this->enabled];
    }
}
