<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;

/** Language card's save request. */
final class HilosI18nLanguageUpdateActionDTO extends AbstractHilosI18nLanguageUpdateActionDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Language card action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_LANGUAGE_UPDATE;
    }
}
