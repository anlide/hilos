<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * SignalConstants - Signal name constants.
 *
 * Defines standard signal names used throughout the framework.
 */
final class SignalConstants
{
    /** @var string Workers ready signal - sent when initial workers are ready */
    public const string WORKERS_READY = 'workers_ready';

    /** @var string Initial agents start signal - queued by WorkerServer when workers are ready */
    public const string INITIAL_AGENTS_START = 'initial_agents_start';

    /** @var string DB sync signal names (operation); canonical values in SignalTypeConstants */
    public const string DB_SYNC_CREATED = SignalTypeConstants::DB_SYNC_CREATED;
    public const string DB_SYNC_UPDATED = SignalTypeConstants::DB_SYNC_UPDATED;
    public const string DB_SYNC_DELETED = SignalTypeConstants::DB_SYNC_DELETED;
    public const string DB_SYNC_CLEARED = SignalTypeConstants::DB_SYNC_CLEARED;

    /** @var string DB re-hydrate signal name; canonical value in SignalTypeConstants */
    public const string DB_REHYDRATE = SignalTypeConstants::DB_REHYDRATE;

    /** @var string Access re-decision announcement signal name; canonical value in SignalTypeConstants */
    public const string PAGE_ACCESS_REASSESS_USER = SignalTypeConstants::PAGE_ACCESS_REASSESS_USER;

    /** @var string RT sync signal names (operation); canonical values in SignalTypeConstants */
    public const string RT_SYNC_CREATED = SignalTypeConstants::RT_SYNC_CREATED;
    public const string RT_SYNC_UPDATED = SignalTypeConstants::RT_SYNC_UPDATED;
    public const string RT_SYNC_DELETED = SignalTypeConstants::RT_SYNC_DELETED;

    /** @var string Page subscription error signal - sent when onSubscribe throws PageSubscriptionException */
    public const string SUBSCRIPTION_PAGE_ERROR = 'subscription_page_error';

    /** @var string Page action error signal - the framework reply when a tracked action throws (also the legacy untracked error) */
    public const string ACTION_ERROR = 'action_error';

    /** @var string Page action success signal - the framework reply confirming a tracked action committed */
    public const string ACTION_SUCCESS = 'action_success';

    /**
     * @var string Reason sent in place of an infrastructural failure's own message,
     *   which never crosses the wire (docs/agents/frontend/wire-protocol.md)
     */
    public const string ACTION_FAILED_REASON = 'The action could not be completed.';
}
