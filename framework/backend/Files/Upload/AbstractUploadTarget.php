<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Files\FileVisibility;
use Hilos\Files\Upload\Check\DuplicateContentCheck;
use Hilos\Files\Upload\Check\StorageLimitCheck;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * An upload target: the policy declared for one kind of file a project accepts (HIL-135).
 *
 * A browser names the target when it declares a file - a chat attachment, an avatar - and the
 * target answers how large the file may be, which types it may have, whether its content is
 * sniffed and whether only a signed-in person may send it. Targets are listed by name in the
 * combined {@see Hilos::uploadTargets()} catalog; the uploads agent creates one instance of each when it
 * starts, so a target takes no constructor arguments.
 *
 * Whether a target requires sign-in has no default on purpose: an upload is an action, and an
 * action says who may perform it out loud (docs/agents/frontend/wire-protocol.md, "Mandatory
 * declared authorization").
 */
abstract class AbstractUploadTarget
{
    /**
     * Asked on every declaration, so a limit read from a setting follows its changes at once.
     *
     * @return int Largest file the target accepts, in bytes
     * @throws HilosException When the target reads its limit from something that cannot be read - a setting
     */
    abstract public function maxBytes(): int;

    /**
     * @return bool Whether only a signed-in connection may declare a file for this target
     */
    abstract public function requiresSignIn(): bool;

    /**
     * @return FileVisibility Who may read a published file received under this target
     */
    abstract public function visibility(): FileVisibility;

    /**
     * Types the target accepts, as exact types or masks like 'image/*'.
     *
     * @return list<string> Accepted types; an empty list accepts any type
     */
    public function acceptedMimeTypes(): array
    {
        return [];
    }

    /**
     * Whether the type of the whole file is read from its content when it arrives.
     *
     * With a list of accepted types, content of another type fails the upload; without one the
     * detected type is only recorded for the consumer.
     *
     * @return bool Whether the content is sniffed
     */
    public function sniffsContent(): bool
    {
        return false;
    }

    /**
     * Checks the project adds after the built-in ones, in their order.
     *
     * Called once, when the uploads agent starts. The framework ships one ready check to add
     * here, {@see DuplicateContentCheck} - `return [new DuplicateContentCheck()];` - and it is off
     * until a target adds it. The storage limit needs nothing here: {@see StorageLimitCheck} is
     * built in wherever the project keeps files.
     *
     * @return list<UploadCheckInterface> Additional checks
     * @throws FeatureNotDeclaredException When a check needs a feature the project did not declare; the agent does not start
     */
    public function extraChecks(): array
    {
        return [];
    }
}
