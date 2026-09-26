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
use Hilos\Hilos;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * Ready check a target switches on: the same person does not keep the same file twice (HIL-136).
 *
 * Off by default; a target adds it in {@see AbstractUploadTarget::extraChecks()}. It compares
 * the fingerprint of the received file ({@see ContentHash}) with the person's published files
 * and with their other complete uploads, and fails the upload when one matches. Only the same
 * person's: a refusal over someone else's file would tell a stranger that such a file is kept.
 * A guest's upload is not judged - a guest owns nothing to compare with.
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
        $contentHash = $upload->contentHash;
        if ($userId === null || $contentHash === null) {
            return null;
        }

        if (Hilos::$db->files->hasOwnerContent($userId, $contentHash)
            || Hilos::$rt->hilosUploads->hasCompleteWithContent($userId, $contentHash, $upload->getId())
        ) {
            return new UploadRefusal(self::CODE, self::MESSAGE);
        }

        return null;
    }
}
