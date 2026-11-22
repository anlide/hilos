<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object;

use Demo\EcommerceShop\Database\Entity\Item\Category;
use Demo\EcommerceShop\Database\Entity\Item\Product;
use Demo\EcommerceShop\Files\CatalogPictureFile;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\File;

/** Serializes new catalog links to a registry file on the shared PRIMARY. */
final class CatalogPictureUsage
{
    /**
     * Locks the file before the owning action writes a new link to it.
     *
     * This is the first read in the action's PRIMARY transaction. After the locking read, the
     * first consistent read sees any link committed by the previous lock holder, even while
     * the bind signal is still in flight. Plain indexed reads of the links avoid locking gaps
     * for other pictures. The registry row is only locked and read here.
     *
     * @param int $fileId Registry id to link
     * @param ?int $ownerUserId Expected publisher, or null for an internal action without a browser publisher
     * @throws ValidationException When the file is missing, belongs to someone else, is bound, or already has a catalog link
     * @throws LogicException When called outside the action's transaction
     * @throws DatabaseException When a registry or catalog read fails
     */
    public static function lockFresh(int $fileId, ?int $ownerUserId): void
    {
        if (!Database::hasOpenTransactions()) {
            throw new LogicException('A catalog picture must be checked inside its write transaction');
        }

        $index = File::getDatabase()->connectionIndex;
        $file = Database::sql(
            'SELECT `owner_user_id`, `bound` FROM ' . File::getSqlTableName() . ' WHERE `id` = ? FOR UPDATE',
            [$fileId],
            index: $index,
        )->row();
        if (
            $file === null
            || (bool)$file[File::bound]
            || ($ownerUserId !== null && ($file[File::owner_user_id] === null || (int)$file[File::owner_user_id] !== $ownerUserId))
        ) {
            throw new ValidationException(CatalogPictureFile::GONE);
        }

        $links = Database::sql(
            'SELECT `id` FROM ' . Category::getSqlTableName() . ' WHERE `picture_file_id` = ?'
            . ' UNION ALL SELECT `id` FROM ' . Product::getSqlTableName() . ' WHERE `picture_file_id` = ? LIMIT 1',
            [$fileId, $fileId],
            index: $index,
        )->row();
        if ($links !== null) {
            throw new ValidationException(CatalogPictureFile::GONE);
        }
    }
}
