<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Pages\I18n\Details\DTO\AbstractHilosI18nLanguageUpdateActionDTO;

/** Languages table row's save request. */
final class HilosI18nLanguageRowUpdateActionDTO extends AbstractHilosI18nLanguageUpdateActionDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Languages list action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_UPDATE;
    }
}
