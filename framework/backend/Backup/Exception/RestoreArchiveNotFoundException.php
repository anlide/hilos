<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

/**
 * Thrown when no stored backup matches the id and scope a restore names.
 *
 * A distinct type rather than a message, because the CLI maps this one refusal to a
 * different exit code (unknown argument, 2) than every other restore failure (1) —
 * classification must not hang on the wording of an exception text.
 */
final class RestoreArchiveNotFoundException extends RestoreFailedException
{
}
