<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent;

/**
 * BackupRunKind - what the supervisor's single in-flight child is doing.
 *
 * The monopoly agent runs at most one child at a time (the single-flight lock), but that
 * child is either a create dump or a restore replay, and the two finish differently: a
 * create records into storage and rescans the index, a restore reports its outcome and
 * lifts protected mode. The kind, captured at spawn, is what routes the poll's finish.
 */
enum BackupRunKind
{
    /** The child is `backup:run`: dumping, archiving, publishing. */
    case CREATE;

    /** The child is `backup:restore-run`: verifying, extracting, importing. */
    case RESTORE;
}
