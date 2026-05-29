<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * AgentConstants - Shared agent identity and routing field keys.
 */
final class AgentConstants
{
    /** @var string Agent unique identifier field key */
    public const string FIELD_AGENT_ID = 'agentId';

    /** @var string Agent type field key */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Agent index field key */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /** @var string Signal routing destination type for agent delivery */
    public const string DESTINATION_TYPE_AGENT = 'agent';
}
