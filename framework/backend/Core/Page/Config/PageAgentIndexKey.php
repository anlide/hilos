<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Config;

use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Page\AbstractPage;

/**
 * Config keys for the per-instance route in AbstractPage::SUBSCRIPTION_AGENT_INDEX.
 *
 * Declared when a page is served not by the agent of a type but by the agent of one
 * entity instance. The declaration names where the instance index comes from and who
 * takes the subscription when no index can be determined:
 *
 * ```php
 * public const array SUBSCRIPTION_AGENT_INDEX = [
 *     PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
 *     PageAgentIndexKey::PARAM => 'chatId',
 *     PageAgentIndexKey::FALLBACK_AGENT_TYPE => AgentType::CHAT,
 * ];
 * ```
 *
 * The shape is the page-side twin of {@see AgentSignalConfigKey}, and for the same
 * reason: a declarative constant is read by topology validation and lands in a
 * project's topology snapshot test, where a hook method would be invisible to both.
 *
 * An empty declaration is the default, and it means the page is not per-instance:
 * {@see AbstractPage::SUBSCRIPTION_AGENT_TYPE} answers alone, exactly as before.
 */
final class PageAgentIndexKey
{
    /**
     * Where the instance index is read from.
     *
     * Accepted values: the cases of {@see PageAgentIndexSource}.
     */
    public const string SOURCE = 'source';

    /**
     * Subscription param carrying the index, required when the source is a param.
     *
     * Accepted param values: positive int or non-empty string, the same form an
     * indexed agent signal accepts for its index field.
     */
    public const string PARAM = 'param';

    /**
     * Agent type that takes the subscription when no index can be determined.
     *
     * Always required. It is the addressee that answers a guest on a "my" page, a
     * subscription whose param is absent or malformed - the refusal is an ordinary
     * page answer from an ordinary agent, and the master never speaks to the client
     * itself.
     */
    public const string FALLBACK_AGENT_TYPE = 'fallbackAgentType';
}
