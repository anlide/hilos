<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Pages\I18n\Details\DTO\AbstractHilosI18nCountryUpdateActionDTO;

/** Country update from one row of the countries table. */
final class HilosI18nCountryRowUpdateActionDTO extends AbstractHilosI18nCountryUpdateActionDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Table row action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_UPDATE;
    }
}
