<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;

/**
 * AbstractPageSubscribeParamsDTO - Base for per-page typed subscribe params.
 *
 * Pages with non-empty route params define a subclass that reads the
 * expected keys from {@see PageRouteParams} via typed accessors and exposes
 * them as readonly fields. Built once per dispatch inside the page family's
 * abstract class, so concrete pages receive a pre-validated typed DTO.
 *
 * Usage:
 *   final class HilosUserPageSubscribeParams extends AbstractPageSubscribeParamsDTO
 *   {
 *       public function __construct(public readonly int $userId) {}
 *
 *       public static function fromPageRouteParams(PageRouteParams $params): static
 *       {
 *           return new self(
 *               userId: $params->requirePositiveInt(HilosPageRouteParams::HILOS_USER_USER_ID),
 *           );
 *       }
 *   }
 */
abstract class AbstractPageSubscribeParamsDTO
{
    /**
     * Builds the typed DTO from the raw subscribe params.
     *
     * Subclasses should delegate all parsing and validation to
     * {@see PageRouteParams} accessors so that parameter errors surface as
     * {@see MissingPageRouteParamException} / {@see InvalidPageRouteParamException}.
     *
     * @param PageRouteParams $params Raw subscribe params from the dispatcher
     * @return static Typed DTO populated from `$params`
     * @throws MissingPageRouteParamException When a required param is absent
     * @throws InvalidPageRouteParamException When a present param fails its typed contract
     */
    abstract public static function fromPageRouteParams(PageRouteParams $params): static;
}
