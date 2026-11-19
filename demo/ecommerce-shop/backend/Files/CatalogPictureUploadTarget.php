<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Files;

use Demo\EcommerceShop\Hilos;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Files\FileVisibility;
use Hilos\Files\Upload\AbstractUploadTarget;
use Hilos\HilosException;

/**
 * A picture of a category or a product; only an administrator uploads one (HIL-2155).
 *
 * The admin modal sends the picture through the framework's browser client under this name, and
 * the shop draws four copies of it, each a 4:3 frame: the table row, the modal preview and cart
 * row, the tiles, and the product page with the admin Overview. A catalog picture is public - the
 * shop front shows it to every visitor. The type is read from the content, so a file is judged by
 * what it is rather than by what the browser called it.
 */
final class CatalogPictureUploadTarget extends AbstractUploadTarget
{
    /** Name the browser declares a catalog picture under. */
    public const string NAME = 'catalog_picture';

    /** Copy for an admin table row. */
    public const string VARIANT_XS = 'catalog_xs';

    /** Copy for the modal preview and a cart row. */
    public const string VARIANT_SM = 'catalog_sm';

    /** Copy for the home page tiles and a category's tiles. */
    public const string VARIANT_TILE = 'catalog_tile';

    /** Copy for the product page and the admin Overview. */
    public const string VARIANT_LG = 'catalog_lg';

    /** Largest picture in bytes: ten mebibytes. */
    private const int MAX_BYTES = 10485760;

    /** Types a catalog picture may have; content of any other type is refused. */
    private const array ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const string MESSAGE_ADMIN_ONLY = 'Only an administrator can upload catalog pictures';

    /** @return int Largest catalog picture in bytes */
    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /** @return bool Always true: a guest only looks at the catalog */
    public function requiresSignIn(): bool
    {
        return true;
    }

    /** @return FileVisibility Every visitor of the shop front sees a catalog picture */
    public function visibility(): FileVisibility
    {
        return FileVisibility::PUBLIC;
    }

    /** @return list<string> JPEG and PNG pictures */
    public function acceptedMimeTypes(): array
    {
        return self::ACCEPTED_MIME_TYPES;
    }

    /** @return bool Always true: the browser's word on the type is not trusted */
    public function sniffsContent(): bool
    {
        return true;
    }

    /**
     * Lets only an administrator declare, replace or cancel a catalog picture.
     *
     * It asks the question the admin pages' gate asks, so an administrator's rights carried into a
     * takeover upload here exactly where they open an admin page. The people the answer reads are
     * read process-wide in every agent, the uploads agent included.
     *
     * @param string $acceptKey Connection declaring, replacing or canceling the upload
     * @param string $clientUploadId Upload id the browser would create, replace or remove
     * @throws ActionUnauthorizedException When the connection has no signed-in person behind it
     * @throws ActionForbiddenException When the person behind the connection is not an administrator
     * @throws HilosException When the people, the session row or the impersonation setting cannot be read
     */
    public function assertBrowserMutationAllowed(string $acceptKey, string $clientUploadId): void
    {
        $userId = ActionUnauthorizedException::requireUser(Hilos::$browser?->resolveActionUserId($acceptKey));
        if (Hilos::$browser?->actsAsAdmin($acceptKey, $userId) !== true) {
            throw new ActionForbiddenException(self::MESSAGE_ADMIN_ONLY);
        }
    }
}
