<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Page\Exception\PageInternalErrorException;

/**
 * A reference to one DB row an open page is about.
 *
 * The collection key and the canonical string of that row's primary key. Not a
 * display name and not a business code. Built only through db(); an empty
 * collection or an empty id is a broken declaration, not a missing row.
 */
final class PageResource
{
    /**
     * @param string $collectionKey DB collection key of the row
     * @param string $idString Canonical string of the row's primary key
     */
    private function __construct(
        public readonly string $collectionKey,
        public readonly string $idString,
    ) {
    }

    /**
     * Names one DB row by its collection and primary key.
     *
     * The primary key is stored as its canonical string. The general API does
     * not require an integer or an autoincrement.
     *
     * @param string $collectionKey DB collection key of the row
     * @param int|string $primaryId Primary key of the row
     * @return self Reference to that row
     * @throws PageInternalErrorException When the collection key or the primary key is empty
     */
    public static function db(string $collectionKey, int|string $primaryId): self
    {
        $idString = (string)$primaryId;
        if ($collectionKey === '' || $idString === '') {
            throw new PageInternalErrorException('Page resource names an empty collection or id');
        }

        return new self($collectionKey, $idString);
    }
}
