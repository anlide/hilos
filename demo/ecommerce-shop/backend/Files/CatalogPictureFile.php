<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Files;

use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\ValidationException;
use Hilos\HilosException;

/**
 * The preliminary browser check for a new catalog picture.
 *
 * The number of the file comes from the browser. Without this check an administrator could hand
 * over a file somebody else published, or one another record already shows; a later picture
 * replacement of the category would then remove a file that is not the category's to remove.
 */
final class CatalogPictureFile
{
    /** Refusal of a file that is not a fresh publication of the person acting (files-registry.md, "Publishing"). */
    public const string GONE = 'This file is gone; upload it again';

    /**
     * Confirms the file is an unbound registry row published by whoever acts on the connection.
     *
     * @param string $acceptKey Accept key of the acting connection
     * @param int $fileId Registry id the browser names
     * @return int The same id, now known to be fresh
     * @throws ValidationException When no such row exists, it belongs to somebody else, or a record already shows it
     * @throws HilosException When the registry cannot be read
     */
    public static function fresh(string $acceptKey, int $fileId): int
    {
        self::freshOwnerId($acceptKey, $fileId);

        return $fileId;
    }

    /**
     * Checks the browser's file and supplies the publisher for the action's durable recheck.
     *
     * @param string $acceptKey Accept key of the acting connection
     * @param int $fileId Registry id the browser names
     * @return int Id of the authenticated publisher
     * @throws ValidationException When the file is not a fresh publication of the person acting
     * @throws HilosException When the registry cannot be read
     */
    public static function freshOwnerId(string $acceptKey, int $fileId): int
    {
        if (!isset(Hilos::$db->files[$fileId])) {
            throw new ValidationException(self::GONE);
        }

        $file = Hilos::$db->files[$fileId];
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null || $file->ownerUserId !== $userId || $file->bound) {
            throw new ValidationException(self::GONE);
        }

        return $userId;
    }
}
