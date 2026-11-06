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
     * @param int $countryId Positive country primary id
     */
    public function __construct(public readonly int $countryId)
    {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Validated country address
     * @throws MissingPageRouteParamException When countryId is missing or empty
     * @throws InvalidPageRouteParamException When countryId is not a canonical positive integer
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        $countryId = $params->requirePositiveInt(HilosPageRouteParams::HILOS_I18N_COUNTRY_ID);
        if ((string)$countryId !== $params->requireString(HilosPageRouteParams::HILOS_I18N_COUNTRY_ID)) {
            throw new InvalidPageRouteParamException(HilosPageRouteParams::HILOS_I18N_COUNTRY_ID, 'expected a canonical positive integer');
        }

        return new static($countryId);
    }
}
