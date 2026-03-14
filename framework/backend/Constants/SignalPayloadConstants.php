<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * SignalPayloadConstants - Common payload field keys for signals.
 */
class SignalPayloadConstants
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

    /** @var string Payload field key for data payload */
    public const string FIELD_DATA = 'data';

    /** @var string Subscription payload key for page */
    public const string SUBSCRIPTION_PAGE_KEY = 'page';

    /** @var string Subscription payload key for params */
    public const string SUBSCRIPTION_PARAMS_KEY = 'params';

    /** @var string Action type value for binary/file signals */
    public const string BINARY_ACTION_TYPE = 'file';
}
