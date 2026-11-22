<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Hilos;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Files\FileVisibility;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\RandomHelper;

/** Fixtures and action dispatch for the admin products list page. */
abstract class AdminProductsPageActionEnvironment extends AdminProductsPageEnvironment
{
    protected const string VISITOR_KEY = 'admin-products-visitor';
    protected const string ADMIN_KEY = 'admin-products-admin';

    protected int $categoryId;
    protected int $visitorId;
    protected int $adminId;
    protected ProductsLibraryAgent $library;

    /**
     * Writes an unbound publication with no bytes on disk.
     *
     * @param int $ownerUserId Person who published the picture
     * @return int Registry id
     */
    protected static function ownPictureFile(int $ownerUserId): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, owner_user_id, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'vase.webp', 'image/webp', 4, hash('sha256', $storedName), $ownerUserId, FileVisibility::PUBLIC->value, 1000],
        );

        return Database::lastInsertId();
    }

    /** Sets up the products agent, an administrator connection and a non-admin connection. */
    protected function connectProductPageUsers(): void
    {
        $this->library = new ProductsLibraryAgent();
        $this->visitorId = self::person('Products visitor', false);
        $this->adminId = self::person('Products admin', true);
        self::unattended(function (): void {
            Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $this->visitorId);
            Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $this->adminId);
        });
    }

    /**
     * @param string $name Product name to send
     * @return array<string, int|string> Valid create payload
     */
    protected function productInput(string $name): array
    {
        return [
            ObjectProduct::name => $name,
            ObjectProduct::categoryId => $this->categoryId,
            ObjectProduct::kind => ProductKind::STOCK->value,
            ObjectProduct::price => '2 100,50',
            ObjectProduct::description => 'Hand-thrown stoneware',
            ObjectProduct::pictureFileId => self::ownPictureFile($this->adminId),
        ];
    }

    /**
     * Dispatches a tracked action to the products library with its ordinary page router.
     *
     * @param string $action Action name
     * @param array<string, int|string> $payload Form fields
     * @param ?string $requestId Client-minted request id, null for an untracked action
     * @param string $acceptKey Acting connection
     */
    protected function dispatchAction(string $action, array $payload, ?string $requestId, string $acceptKey = self::ADMIN_KEY): void
    {
        $router = new PageSignalRouter(
            new HilosPageFactory($this->library, Hilos::class),
            new ActionRouteConfig(Hilos::getPageActionRoutes()),
        );
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->library->getId(), acceptKey: $acceptKey),
            static fn () => $router->dispatchAction(new WebSocketActionSignalDTO($acceptKey, $action, $payload, $requestId), 'websocket'),
        );
    }

    /**
     * Drains the router queue and checks that every action acknowledgement reached the expected connection.
     *
     * @param string $acceptKey Expected action recipient
     * @param int $count Expected number of acknowledgements
     * @return list<PageActionSuccessSignalData|PageActionErrorSignalData> Action acknowledgements in queue order
     */
    protected function actionFrames(string $acceptKey, int $count = 0): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (in_array($signal->signalName->getName(), [SignalConstants::ACTION_SUCCESS, SignalConstants::ACTION_ERROR], true)) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame($acceptKey, $signal->data->targetAcceptKey);
                $frames[] = $signal->data->data;
            }
        }
        self::assertCount($count, $frames);

        return $frames;
    }

    /**
     * Writes a person past the users library and drops a verdict an earlier case of this process may have
     * remembered for the same id.
     *
     * @param string $name Name of the person
     * @param bool $admin Whether the person is an administrator
     * @return int Id of the new person
     */
    private static function person(string $name, bool $admin): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, ?, 0)', [$name, (int)$admin]);
        $userId = Database::lastInsertId();
        AccountStandingResolver::forget($userId);

        return $userId;
    }
}
