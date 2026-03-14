<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * SignalPayloadConstants - Common payload field keys for signals.
 */
class SignalPayloadConstants
{
    public const string FIELD_TYPE = 'type';
    public const string FIELD_PAGE = 'page';
    public const string FIELD_PARAMS = 'params';
    public const string FIELD_GROUP = 'group';
    public const string FIELD_ACTION = 'action';
    public const string FIELD_DATA = 'data';

    public const string SUBSCRIPTION_PAGE_KEY = 'page';
    public const string SUBSCRIPTION_PARAMS_KEY = 'params';

    public const string BINARY_ACTION_TYPE = 'file';
}
