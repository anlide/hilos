<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\Definition\ViewportTable;

/**
 * Exception: a bulk action was asked of a table that does not offer it.
 *
 * Two askings end here, and both are the same sentence to the person who pressed: a table key that
 * is not one of the tables of the page it was sent to ({@see AbstractPage::startBulkAction()}), and
 * an action the table did not declare among its mass operations ({@see ViewportTable::bulkActions()}).
 * A page's own buttons can produce neither, so the reason is short and the details stay in the log.
 *
 * It refuses through {@see TableActionException} for the reason the busy refusal does: only the
 * ValidationException family crosses the wire with its own words.
 */
final class TableBulkActionNotOfferedException extends TableActionException
{
    /** What the person who pressed the button is told. */
    public const string REASON = 'This table does not offer that bulk action';

    /**
     * Refuses a bulk action this table does not offer.
     *
     * @param string $tableKey Table the request named, kept for the log rather than for the client
     * @param string $action Action the request carried, kept for the log rather than for the client
     */
    public function __construct(public readonly string $tableKey, public readonly string $action)
    {
        parent::__construct(self::REASON);
    }
}
