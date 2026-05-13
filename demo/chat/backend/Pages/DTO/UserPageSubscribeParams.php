<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO;

use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/**
 * Typed subscribe params for the chat user detail page.
 */
final class UserPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /** @var string Route param key mirroring the Vue Router segment name `id`. */
    public const string USER_ID = 'id';

    /**
     * @param int $userId User primary key, always `> 0`
     */
    public function __construct(public readonly int $userId)
    {
    }

    /**
     * Reads the `id` route param as a positive integer.
     *
     * @param PageRouteParams $params Raw subscribe params
     * @throws MissingPageRouteParamException When `id` is absent
     * @throws InvalidPageRouteParamException When `id` is non-numeric or `<= 0`
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new self(
            userId: $params->requirePositiveInt(self::USER_ID),
        );
    }
}
