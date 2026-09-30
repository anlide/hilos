<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\Check;

use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\DatabaseException;
use Hilos\Files\ContentHash;
use Hilos\Files\Upload\AbstractUploadTarget;
use Hilos\Files\Upload\UploadCheckInterface;
use Hilos\Files\Upload\UploadDeclaration;
use Hilos\Files\Upload\UploadRefusal;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * Ready check a target switches on: the same person does not keep the same file twice (HIL-136).
 *
 * Off by default; a target adds it in {@see AbstractUploadTarget::extraChecks()}. It compares
 * the fingerprint of the received file ({@see ContentHash}) with the person's bound files in the
 * registry and with their other complete uploads on every connection, and fails the upload when
 * one matches. Only the same person's: a refusal over someone else's file would tell a stranger
 * that such a file is kept. Only bound files: a published row nothing links to - the sending
 * fell through, and the row waits for the sweeper - is a file the person sees nowhere, and a
 * refusal over it is worse than a missed duplicate.
 *
 * A guest's upload is not judged on arrival - a guest owns nothing to compare with. Its draft is
 * judged when it is published after the guest signed in: the uploads agent asks
 * {@see self::checkOwner()} for the person signed in now ({@see UploadsAgent}).
 *
 * It reads the registry, so a project that keeps no files cannot use it: the check refuses to
 * be created there, and the uploads agent, which creates its checks when it starts, does not
 * start.
 */
final readonly class DuplicateContentCheck implements UploadCheckInterface
{
    /** Code of the refusal. */
    public const string CODE = 'duplicate_content';

    /** Sentence of the refusal. */
    private const string MESSAGE = 'This file is already uploaded';

    /**
     * @throws FeatureNotDeclaredException When the project does not declare HilosFeature::FILES
     */
    public function __construct()
    {
        if (!Hilos::hasFeature(HilosFeature::FILES)) {
            throw FeatureNotDeclaredException::forFeature(HilosFeature::FILES);
        }
    }

    /**
     * Nothing to say: the content is not known before it arrives.
     *
     * @param UploadDeclaration $declaration What the browser declared
     * @return null Always
     */
    public function checkDeclared(UploadDeclaration $declaration): ?UploadRefusal
    {
        return null;
    }

    /**
     * @param HilosUpload $upload Upload whose bytes have all arrived, with its fingerprint
     * @return ?UploadRefusal Refusal when the same person already keeps this content, or null
     * @throws DatabaseException When the registry cannot be read
     * @throws RtActionsStateCollectionNullException When the uploads cannot be read
     */
    public function checkReceived(HilosUpload $upload): ?UploadRefusal
    {
        $userId = $upload->userId;
        if ($userId === null) {
            return null;
        }

        return $this->checkOwner($upload, $userId);
    }

    /**
     * Judges an upload against what one person keeps: their bound files in the registry and
     * their other complete uploads on every connection.
     *
     * On arrival the person is the one who declared the upload ({@see self::checkReceived()});
     * on the publication of a guest's draft it is the one signed in now.
     *
     * @param HilosUpload $upload Upload whose bytes have all arrived, with its fingerprint
     * @param int $ownerUserId Person the file would belong to
     * @return ?UploadRefusal Refusal when that person already keeps this content, or null
     * @throws DatabaseException When the registry cannot be read
     * @throws RtActionsStateCollectionNullException When the uploads cannot be read
     */
    public function checkOwner(HilosUpload $upload, int $ownerUserId): ?UploadRefusal
    {
        $contentHash = $upload->contentHash;
        if ($contentHash === null) {
            return null;
        }

        if (Hilos::$db->files->hasOwnerContent($ownerUserId, $contentHash)
            || Hilos::$rt->hilosUploads->hasCompleteWithContent($ownerUserId, $contentHash, $upload->getId())
        ) {
            return new UploadRefusal(self::CODE, self::MESSAGE);
        }

        return null;
    }
}
