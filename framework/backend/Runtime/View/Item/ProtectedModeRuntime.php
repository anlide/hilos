<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Actions\Item\ProtectedModeRuntimeActions;

/**
 * Read-only view of the protected-mode runtime singleton.
 *
 * The row carries the freeze this node is under: which phase it is in and whose
 * connection and agent are allowed to keep working through it. Both lockdown checks —
 * the master welcome path and the browser page guards — read it through
 * {@see locksOut()}. Writing goes through {@see ProtectedModeRuntimeActions}, so each
 * phase change reaches the other workers on this node.
 *
 * @extends RtItem<StateProtectedModeRuntime>
 *
 * @property-read string $phase Current lifecycle phase of the protected mode
 * @property-read ?string $operation Operation the initiator is running; null when inactive
 * @property-read ?string $initiatorAcceptKey Accept key let through the lockdown; null when none
 * @property-read ?string $initiatorSessionTokenHash Hash of the initiator browser's session token; null when none
 * @property-read ?string $initiatorAgentType Agent type of the initiator agent; null when inactive
 * @property-read ?int $initiatorAgentIndex Agent index of the initiator agent; null when inactive
 * @property-read ?string $initiatorNodeId Node hosting the initiator agent; null when inactive
 * @property-read ?int $startedAt Epoch seconds the freeze began; null when inactive
 * @property-read ?int $activatedAt Epoch seconds the freeze reached active; null before it does
 * @property-read ?int $progressAt Epoch seconds of the last progress mark behind the freeze; null when none
 * @property-read list<string> $passHashes Hashes of the passes minted for the verification; empty otherwise
 * @property-read list<string> $admittedSessionTokenHashes Session token hashes let in by a pass; empty otherwise
 * @property-read ProtectedModeRuntimeActions $actions Write operations for the runtime singleton
 */
final class ProtectedModeRuntime extends RtItem
{
    /**
     * @param StateProtectedModeRuntime $state Backing runtime state
     */
    public function __construct(StateProtectedModeRuntime $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|array<int, string>|ProtectedModeRuntimeActions|null Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|int|array|ProtectedModeRuntimeActions|null
    {
        return match ($name) {
            StateProtectedModeRuntime::phase => $this->_state->phase,
            StateProtectedModeRuntime::operation => $this->_state->operation,
            StateProtectedModeRuntime::initiatorAcceptKey => $this->_state->initiatorAcceptKey,
            StateProtectedModeRuntime::initiatorSessionTokenHash => $this->_state->initiatorSessionTokenHash,
            StateProtectedModeRuntime::initiatorAgentType => $this->_state->initiatorAgentType,
            StateProtectedModeRuntime::initiatorAgentIndex => $this->_state->initiatorAgentIndex,
            StateProtectedModeRuntime::initiatorNodeId => $this->_state->initiatorNodeId,
            StateProtectedModeRuntime::startedAt => $this->_state->startedAt,
            StateProtectedModeRuntime::activatedAt => $this->_state->activatedAt,
            StateProtectedModeRuntime::progressAt => $this->_state->progressAt,
            StateProtectedModeRuntime::passHashes => $this->_state->passHashes,
            StateProtectedModeRuntime::admittedSessionTokenHashes => $this->_state->admittedSessionTokenHashes,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * Whether a connection holding this accept key is locked out while the freeze is up.
     *
     * The rule itself lives on the state row ({@see StateProtectedModeRuntime::locksOut()})
     * together with the phase semantics it depends on; this delegate is what lets a caller
     * outside the runtime ask the question without holding the backing row.
     *
     * @param ?string $acceptKey Connection accept key to test, or null when none is known
     * @param ?string $sessionTokenHash Hash of the connection's session token, or null when it carries no session
     * @return bool Whether the connection is locked out right now
     */
    public function locksOut(?string $acceptKey, ?string $sessionTokenHash): bool
    {
        return $this->_state->locksOut($acceptKey, $sessionTokenHash);
    }

    /**
     * Whether this browser session presented a valid pass and was let in for the verification.
     *
     * The rule lives on the state row ({@see StateProtectedModeRuntime::admits()}); this
     * delegate is what lets the admission path ask whether a session is already recorded
     * without holding the backing row.
     *
     * @param ?string $sessionTokenHash Hash of the connection's session token, or null when it carries no session
     * @return bool Whether the session behind this connection holds a pass admitted right now
     */
    public function admits(?string $sessionTokenHash): bool
    {
        return $this->_state->admits($sessionTokenHash);
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
