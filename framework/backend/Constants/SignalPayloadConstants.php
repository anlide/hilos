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

    /** @var string Payload field key for data payload */
    public const string FIELD_DATA = 'data';

    /** @var string Payload field key for inner payload class name (deserialization hint) */
    public const string FIELD_DATA_TYPE = 'dataType';

    /** @var string Payload field key for WebSocket accept key */
    public const string FIELD_ACCEPT_KEY = 'acceptKey';

    /** @var string Subscription payload key for page (same wire key as FIELD_PAGE) */
    public const string SUBSCRIPTION_PAGE_KEY = self::FIELD_PAGE;

    /** @var string Subscription payload key for params (same wire key as FIELD_PARAMS) */
    public const string SUBSCRIPTION_PARAMS_KEY = self::FIELD_PARAMS;

    /** @var string Action type value for binary/file signals */
    public const string BINARY_ACTION_TYPE = 'file';
}
