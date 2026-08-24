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

    /** @var string Accept keys of the node's live sockets, carried by the agent-start frame (HIL-664) */
    public const string FIELD_LIVE_ACCEPT_KEYS = 'liveAcceptKeys';

    /** @var string Agent type field key */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Agent index field key */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    /** @var string Separator between agent type and index in agent ID (format: "type" or "type:index") */
    public const string ID_SEPARATOR = ':';

    /** @var int Maximum number of parts when splitting agent ID by ID_SEPARATOR */
    public const int ID_MAX_PARTS = 2;
}
