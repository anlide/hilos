<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Database\ChatDbContext;
use Hilos\Database\View\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Actions\Item\ConnectionActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\HilosSessionConnection;
use Hilos\HilosException;

/**
 * Read-only runtime item for a connection state row plus virtual user links.
 *
 * Stands on the framework {@see HilosSessionConnection} base — the session stage,
 * which reads the accept key, the session token, the bound user and the item's
 * actions — and adds chat's own per-socket fields and the virtual links that turn
 * a bound user id into rows. Use `Hilos::$rt->connections` for collection access.
 * Per-connection writes go through this item's actions.
 *
 * @extends HilosSessionConnection<StateConnection>
 *
 * @property-read string $outboundModerationPhase Current moderation phase
 * @property-read ?string $outboundModerationMessage Submitted message text, or null when none is
 * @property-read ?string $outboundModerationReason Rejection or unavailable reason, or null
 * @property-read int $outboundModerationUpdatedAt Last moderation update unix time
 * @property-read list<string> $outboundModerationAttachments Client ids of the uploads the moderated message carries
 * @property-read string $renameModerationPhase Current rename moderation phase
 * @property-read ?string $renameModerationName Requested display name, or null when none is
 * @property-read ?string $renameModerationReason Rename rejection or unavailable reason, or null
 * @property-read int $renameModerationUpdatedAt Last rename moderation update unix time
 * @property-read ?User $user User row or null if not found in DB view
 * @property-read ?ChatUserState $userState Runtime user state row or null if not found
 * @property-read ConnectionActions $actions Write operations for this connection
 */
final class Connection extends HilosSessionConnection
{
    /**
     * @param StateConnection $state Backing state, same as parent contract
     */
    public function __construct(StateConnection $state)
    {
        parent::__construct($state);
    }

    /**
     * Delegates chat's own keys to the backing state; virtual links load DB user and runtime user
     * state. The base fields and the item actions are resolved by the framework base.
     *
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws DatabaseException When reading the user collection fails
     * @throws InvalidArgumentException When a loaded user object does not match the collection
     * @throws LogicException When the user collection is not configured
     * @throws HilosException When an inherited getter or an implementation's relation read fails
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            StateConnection::outboundModerationPhase => $this->_state->outboundModerationPhase,
            StateConnection::outboundModerationMessage => $this->_state->outboundModerationMessage,
            StateConnection::outboundModerationReason => $this->_state->outboundModerationReason,
            StateConnection::outboundModerationUpdatedAt => $this->_state->outboundModerationUpdatedAt,
            StateConnection::outboundModerationAttachments => $this->_state->outboundModerationAttachments,
            StateConnection::renameModerationPhase => $this->_state->renameModerationPhase,
            StateConnection::renameModerationName => $this->_state->renameModerationName,
            StateConnection::renameModerationReason => $this->_state->renameModerationReason,
            StateConnection::renameModerationUpdatedAt => $this->_state->renameModerationUpdatedAt,
            ChatDbContext::user => $this->_state->userId !== null ? Hilos::$db->users[$this->_state->userId] : null,
            ConnectionRuntimeConstants::userState => $this->_state->userId !== null
                ? Hilos::$rt->userStates[$this->_state->userId]
                : null,
            default => parent::__get($name),
        };
    }
}
