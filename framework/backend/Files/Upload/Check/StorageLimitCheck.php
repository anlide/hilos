<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\Check;

use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\Upload\UploadCheckInterface;
use Hilos\Files\Upload\UploadDeclaration;
use Hilos\Files\Upload\UploadRefusal;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * Built-in check wherever the project keeps files: the storage does not outgrow its limit (HIL-136).
 *
 * The limit is the `files.max_total_bytes` setting, read on every declaration, and covers the
 * whole storage at once - one disk, one limit for every target. What it holds is the registry's
 * files, bound or not, together with every upload that holds a file, on any connection: a
 * declared upload has its place reserved from the moment it is accepted. Judged on the
 * declaration alone for that reason - neither the arrival nor the publication can take more
 * than was reserved.
 */
final readonly class StorageLimitCheck implements UploadCheckInterface
{
    /** Code of the refusal. */
    public const string CODE = 'storage_limit';

    /** Sentence of the refusal. */
    private const string MESSAGE = 'Storage limit would be exceeded';

    /**
     * @param UploadDeclaration $declaration What the browser declared
     * @return ?UploadRefusal Refusal when the declared size would take the storage past its limit, or null
     * @throws SettingException When the limit cannot be read
     * @throws DatabaseException When the limit or the registry's total cannot be read
     * @throws RtActionsStateCollectionNullException When the uploads cannot be read
     */
    public function checkDeclared(UploadDeclaration $declaration): ?UploadRefusal
    {
        $limit = Hilos::$setting[FilesSettingsCatalog::MAX_TOTAL_BYTES_KEY]->int();
        if ($limit <= 0) {
            return null;
        }

        $taken = Hilos::$db->files->totalSize() + Hilos::$rt->hilosUploads->sumHoldingBytes();
        if ($taken + $declaration->size > $limit) {
            return new UploadRefusal(self::CODE, self::MESSAGE);
        }

        return null;
    }

    /**
     * Nothing to say: the place was reserved by the declaration.
     *
     * @param HilosUpload $upload Received upload
     * @return null Always
     */
    public function checkReceived(HilosUpload $upload): ?UploadRefusal
    {
        return null;
    }
}
