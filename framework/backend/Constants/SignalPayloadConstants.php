<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * SignalPayloadConstants - Common payload field keys for signals.
 */
final class SignalPayloadConstants
{
    /** @var string Payload field key for signal type */
    public const string FIELD_TYPE = 'type';

    /** @var string Payload field key for page identifier */
    public const string FIELD_PAGE = 'page';

    /** @var string Payload field key for params */
    public const string FIELD_PARAMS = 'params';

    /** @var string Payload field key for group identifier */
    public const string FIELD_GROUP = 'group';

    /** @var string Payload field key for action name */
    public const string FIELD_ACTION = 'action';

    /** @var string Payload field key for the client-minted action request id (reply correlation) */
    public const string FIELD_REQUEST_ID = 'requestId';

    /** @var string Payload field key for a backend-authored action outcome sentence (success toast text) */
    public const string FIELD_MESSAGE = 'message';

    /**
     * @var string Payload field key for a tracked action's optional domain reply (named reply, not
     *     data: data holds the signal body in the envelope)
     */
    public const string FIELD_REPLY = 'reply';

    /** @var string Payload field key for data payload */
    public const string FIELD_DATA = 'data';

    /** @var string Payload field key for inner payload class name (deserialization hint) */
    public const string FIELD_DATA_TYPE = 'dataType';

    /** @var string Payload field key for WebSocket accept key */
    public const string FIELD_ACCEPT_KEY = 'acceptKey';

    /** @var string Payload field key for table key */
    public const string FIELD_TABLE_KEY = 'tableKey';

    /** @var string Payload field key for a table viewport filter map */
    public const string FIELD_FILTER = 'filter';

    /** @var string Payload field key for a table viewport order (list of {field, direction}) */
    public const string FIELD_SORT = 'sort';

    /** @var string Payload field key for a table viewport limit */
    public const string FIELD_LIMIT = 'limit';

    /** @var string Payload field key for the place a table viewport is taken from */
    public const string FIELD_ANCHOR = 'anchor';

    /** @var string Payload field key for the side of the anchor a table viewport is taken from */
    public const string FIELD_ANCHOR_DIRECTION = 'anchorDirection';

    /** @var string Payload field key for the page a table viewport jumps to */
    public const string FIELD_PAGE_INDEX = 'pageIndex';

    /** @var string Payload field key for the fields inside a table row's slots that the tab draws */
    public const string FIELD_RENDERED = 'rendered';

    /** @var string Payload field key for the row a tab holds in focus for an open dialog */
    public const string FIELD_ROW_KEY = 'rowKey';

    /** @var string Payload field key for the place the first row of a table window sits at */
    public const string FIELD_FIRST_ANCHOR = 'firstAnchor';

    /** @var string Payload field key for the place the last row of a table window sits at */
    public const string FIELD_LAST_ANCHOR = 'lastAnchor';

    /** @var string Payload field key for the windows a tab already holds, told on a page subscription */
    public const string FIELD_TABLE_WINDOWS = 'tableWindows';

    /** @var string Subscription payload key for page (same wire key as FIELD_PAGE) */
    public const string SUBSCRIPTION_PAGE_KEY = self::FIELD_PAGE;

    /** @var string Subscription payload key for params (same wire key as FIELD_PARAMS) */
    public const string SUBSCRIPTION_PARAMS_KEY = self::FIELD_PARAMS;

    /** @var string Action type value for binary/file signals */
    public const string BINARY_ACTION_TYPE = 'file';
}
