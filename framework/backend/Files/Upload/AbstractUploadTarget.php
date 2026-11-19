<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Page\PageAccessLevel;
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
 * sniffed and who may send it - its access level, the three a page declares. Targets are listed by
 * name in the combined {@see Hilos::uploadTargets()} catalog; the uploads agent creates one instance
 * of each when it starts, so a target takes no constructor arguments.
 *
 * The access level has no default on purpose: an upload is an action, and an action says who may
 * perform it out loud (docs/agents/frontend/wire-protocol.md, "Mandatory declared authorization").
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
     * Who may declare a file of this target, judged by the uploads agent before anything else.
     *
     * PUBLIC lets any connection in, AUTHENTICATED a signed-in one. ADMIN is judged the way an admin
     * page is: with the admin view mode on, a viewer of the mode - a guest too - meets the mode's
     * refusal; otherwise a guest has to sign in and a person who does not act as an administrator is
     * refused. Canceling a file is judged by the level on an ADMIN target alone: a connection cancels
     * its own file of a PUBLIC or AUTHENTICATED target without being asked who it is now, because the
     * browser cancels what a person declared after that person has signed out
     * (docs/agents/architecture/uploads.md).
     *
     * @return PageAccessLevel Access level of the target
     */
    abstract public function accessLevel(): PageAccessLevel;

    /** @return bool Whether publication requires the owner's live hold and final consumer approval */
    public function requiresUploadHoldForPublication(): bool
    {
        return false;
    }

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
     * Guards browser init and cancel before any upload row or file is changed.
     *
     * Init asks the declared target and, on replacement, the existing target as well. Cancel
     * asks the existing target. Both run before a replaced row or its file is removed, and
     * after the target's {@see self::accessLevel()} has let the connection in, where that level
     * is judged: on init always, on cancel only for an ADMIN target. A cancel of a PUBLIC or
     * AUTHENTICATED target may reach this hook from a connection that is already a guest's - the
     * browser cancels uploads after a sign-out - so the hook must not require a sign-in there.
     * "Only an administrator" is declared by the ADMIN level, not by this hook.
     * A consumer may protect a held submission through its project's runtime View collection.
     * Internal publication and cleanup do not call this guard. The default permits mutation.
     *
     * @param string $acceptKey Connection declaring, replacing or canceling the upload
     * @param string $clientUploadId Upload id the browser would create, replace or remove
     * @throws HilosException When the consumer refuses mutation or its runtime policy cannot be read
     */
    public function assertBrowserMutationAllowed(string $acceptKey, string $clientUploadId): void
    {
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
