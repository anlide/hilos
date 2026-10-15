<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\Core\Exception\InvalidFormatException;

/** One locale offered by the country's edit form, including switched-off locales. */
final readonly class CountryLocaleChoice
{
    public const string localeId = 'localeId';
    public const string code = 'code';
    public const string enabled = 'enabled';

    /**
     * @param int $localeId Persisted locale ID
     * @param string $code Locale code
     * @param bool $enabled Whether the locale is switched on
     * @throws InvalidFormatException When the locale ID is not positive
     */
    public function __construct(public int $localeId, public string $code, public bool $enabled)
    {
        if ($localeId <= 0) {
            throw new InvalidFormatException('Invalid locale ID');
        }
    }

    /** @return array{localeId: int, code: string, enabled: bool} Locale choice on the wire */
    public function toArray(): array
    {
        return [self::localeId => $this->localeId, self::code => $this->code, self::enabled => $this->enabled];
    }
}
