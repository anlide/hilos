<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO;

use Demo\Chat\Pages\BotPage;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/**
 * BotPageSubscribeParams - Typed subscribe params for the chat bot detail page.
 *
 * Parsed from the raw route params by {@see BotPage::onSubscribe()}. Guarantees
 * a positive integer `botId` read from the `id` route segment
 * (`/bot/:id` in the Vue router).
 */
final class BotPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /** @var string Route param key mirroring the Vue Router segment name `id`. */
    public const string BOT_ID = 'id';

    /**
     * @param int $botId Bot primary key, always `> 0`
     */
    public function __construct(public readonly int $botId)
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
            botId: $params->requirePositiveInt(self::BOT_ID),
        );
    }
}
