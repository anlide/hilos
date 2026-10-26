<?php

declare(strict_types=1);

namespace Hilos\Pages\Moderation\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** Typed address of one code-declared moderation kind. */
final class HilosModerationKindSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /** @param string $kind Nonempty kind key; the page checks directory membership */
    public function __construct(public readonly string $kind)
    {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Parsed kind address without a domain lookup
     * @throws MissingPageRouteParamException When kind is missing or empty
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static($params->requireString(HilosPageRouteParams::HILOS_MODERATION_KIND));
    }
}
