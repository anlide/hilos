<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** Typed address of a language page. */
final class HilosI18nLanguagePageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /**
     * @param int $languageId Positive language primary id
     */
    public function __construct(public readonly int $languageId)
    {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Validated language address
     * @throws MissingPageRouteParamException When languageId is missing or empty
     * @throws InvalidPageRouteParamException When languageId is not a canonical positive integer
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        $languageId = $params->requirePositiveInt(HilosPageRouteParams::HILOS_I18N_LANGUAGE_ID);
        if ((string)$languageId !== $params->requireString(HilosPageRouteParams::HILOS_I18N_LANGUAGE_ID)) {
            throw new InvalidPageRouteParamException(HilosPageRouteParams::HILOS_I18N_LANGUAGE_ID, 'expected a canonical positive integer');
        }

        return new static($languageId);
    }
}
