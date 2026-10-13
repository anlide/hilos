<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;

/** Country update from its main card. */
final class HilosI18nCountryUpdateActionDTO extends AbstractHilosI18nCountryUpdateActionDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Card action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_COUNTRY_UPDATE;
    }
}
