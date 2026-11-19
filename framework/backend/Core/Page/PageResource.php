<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\Exception\PageInternalErrorException;

/**
 * A reference to one DB row an open page is about.
 *
 * The collection key and the canonical string of that row's primary key. Not a
 * display name and not a business code. Built only through db(); an empty
 * collection or an empty id is a broken declaration, not a missing row.
 *
 * A page whose address is read from a column of that row says so with addressedBy(): the
 * route param and the column it is read from. When an update of the row changes that column
 * while the page is open, the browser fan-out moves the window to the new address
 * ({@see BrowserContext::flushToSignalRouter()}).
 */
final class PageResource
{
    /**
     * @param string $collectionKey DB collection key of the row
     * @param string $idString Canonical string of the row's primary key
     * @param ?string $addressParam Route param the page's address reads from the row, or null when it reads none
     * @param ?string $addressColumn Column of the row that param is read from, or null when the address reads none
     */
    private function __construct(
        public readonly string $collectionKey,
        public readonly string $idString,
        public readonly ?string $addressParam = null,
        public readonly ?string $addressColumn = null,
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

    /**
     * Says which route param of the page's address is read from which column of the row.
     *
     * The column is named as the row of a DB fact carries it, which is how the Object
     * constants of the row write it. Returns a new reference; this one does not change.
     *
     * @param string $param Route param of the page's address
     * @param string $column Column of the row the param is read from
     * @return self The same row, with its address declared
     * @throws PageInternalErrorException When the param or the column is empty
     */
    public function addressedBy(string $param, string $column): self
    {
        if ($param === '' || $column === '') {
            throw new PageInternalErrorException('Page resource address names an empty param or column');
        }

        return new self($this->collectionKey, $this->idString, $param, $column);
    }
}
