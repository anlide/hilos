<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** Typed address of a country page. */
final class HilosI18nCountryPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /**
     * @param string $countryCode Two-letter lowercase country code
     */
    public function __construct(public readonly string $countryCode)
    {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Validated country address
     * @throws MissingPageRouteParamException When countryCode is missing or empty
     * @throws InvalidPageRouteParamException When countryCode is not two lowercase ASCII letters
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        $countryCode = $params->requireString(HilosPageRouteParams::HILOS_I18N_COUNTRY_CODE);
        if (preg_match('/^[a-z]{2}$/D', $countryCode) !== 1) {
            throw new InvalidPageRouteParamException(HilosPageRouteParams::HILOS_I18N_COUNTRY_CODE, 'expected two lowercase letters');
        }

        return new static($countryCode);
    }
}
