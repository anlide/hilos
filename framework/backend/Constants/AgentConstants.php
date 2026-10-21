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

    /** @var string Why a start did not finish, as the failure said it (HIL-629) */
    public const string FIELD_REASON = 'reason';

    /** @var string Separator between agent type and index in agent ID (format: "type" or "type:index") */
    public const string ID_SEPARATOR = ':';

    /** @var int Maximum number of parts when splitting agent ID by ID_SEPARATOR */
    public const int ID_MAX_PARTS = 2;

    /**
     * @var float Seconds a worker gives an agent's start - and a subscription - to get the state it reads before refusing it
     *
     * The worker's budget for its data sources, and one phase of a start rather than the whole of
     * it (HIL-629). The master keeps no clock of this length: the phase before it - waiting for a
     * worker of the agent's type to connect, monopolistic or regular - is the master's own and runs
     * on a limit of its own (HIL-1671), and the frames the master holds for a starting agent end on
     * the start's report rather than on any deadline (HIL-1040). Moving this budget moves neither
     * of them.
     *
     * Long enough that only a master in real trouble misses it - the answer is one round trip over
     * a link the worker is already reading - and short enough that a browser is told the page
     * cannot be served rather than left holding an open request.
     */
    public const float START_DEADLINE_SECONDS = 5.0;
}
