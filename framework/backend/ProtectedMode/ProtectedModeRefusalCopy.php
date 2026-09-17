<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

/**
 * Human-readable operator-facing refusal messages for protected-mode requests.
 *
 * These reasons are delivered to the initiator agent when an enable or re-entry request
 * cannot be granted, and from there reach RestoreRuntime::$failureReason and the operator UI.
 */
final class ProtectedModeRefusalCopy
{
    public const string ANOTHER_OPERATION = 'Another operation is already running on this node; wait for it to finish';
    public const string FOREIGN_FREEZE = 'The freeze on this node belongs to another operation';
    public const string NO_LEADER = 'The cluster has no leader right now; try again in a moment';
    public const string NO_RUNTIME_ROW = 'This node cannot enter protected mode';

    private function __construct()
    {
    }
}
