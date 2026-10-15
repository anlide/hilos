<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog\DTO;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** Live table address; existence is checked by the page. */
final class HilosChangeLogTablePageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /** @param string $tableId Requested live table name */
    public function __construct(public readonly string $tableId)
    {
    }

    /**
     * @param PageRouteParams $params Raw route parameters
     * @return static Parsed table name
     * @throws MissingPageRouteParamException When the table name is absent or empty
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(tableId: $params->requireString(HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID));
    }
}
