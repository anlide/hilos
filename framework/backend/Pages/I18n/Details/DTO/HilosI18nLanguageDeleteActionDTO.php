<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosSignalConstants;

/** Language card's removal request. */
final class HilosI18nLanguageDeleteActionDTO extends AbstractHilosI18nLanguageDeleteActionDTO
{
    public const array SECRET_FIELDS = [];

    /** @return string Language card action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_I18N_LANGUAGE_DELETE;
    }
}
