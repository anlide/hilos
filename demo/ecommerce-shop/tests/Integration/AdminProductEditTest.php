<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminProductPage;
use Demo\EcommerceShop\Pages\AdminProductsPage;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\DTO\ProductPicturePublishActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductUpdateActionDTO;
use Demo\EcommerceShop\Tables\Product\ProductsTable;
use Demo\EcommerceShop\Tables\Product\ProductTableRow;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;

/** Tracked edits reach the products library through the admin page and keep open surfaces current. */
#[IntegrationTest]
final class AdminProductEditTest extends AdminProductsPageActionEnvironment
{
    /** Connects an administrator and a signed-in visitor on an empty catalog with one category. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $this->categoryId = self::category('Ceramics');
        $this->connectProductPageUsers();
    }

    /** @param ProductKind $kind Kind of product to edit */
    #[DataProvider('kinds')]
    public function testAnEditKeepsTheKindAndPictureAndRefreshesBothOpenSurfaces(ProductKind $kind): void
    {
        $productId = $this->holdProduct($kind);
        $initial = $this->openOverview(self::ADMIN_KEY, $productId);
        $this->openFilteredProducts($productId);
        $before = self::storedProduct($productId);

        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_UPDATE, $this->input($initial), 'edit');
        $this->assertSaved('edit', 'Glazed vase');
        $this->deliverProductEdit($productId, $before, 'edit');

        $frames = self::takeFrames([
            self::ADMIN_KEY => SignalTypeConstants::PAGE_RESPONSE,
            self::VISITOR_KEY => SignalTypeConstants::TABLE_VIEWPORT_DELTA,
        ]);
        $card = self::cardOf($frames[self::ADMIN_KEY]);
        $this->assertEditedCard($card, $initial);
        self::assertSame($kind, $card[ObjectProduct::kind]);
        self::assertSame($initial[ObjectProduct::pictureFileId], $card[ObjectProduct::pictureFileId]);
        self::assertSame($initial[AdminProductBrowserData::FIELD_PICTURE_URL], $card[AdminProductBrowserData::FIELD_PICTURE_URL]);

        $delta = $frames[self::VISITOR_KEY];
        self::assertInstanceOf(TableViewportDeltaDTO::class, $delta);
        self::assertSame(AdminProductsPage::PAGE, $delta->page);
        self::assertSame(ProductsTable::TABLE, $delta->tableKey);
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        self::assertFalse($delta->own);
        self::assertSame($productId, $delta->rowKey);
        $row = $delta->row[PagePayload::slots]['product'];
        self::assertSame('Glazed vase', $row[ProductTableRow::name]);
        self::assertSame(210050, $row[ProductTableRow::price]);
        self::assertSame($this->categoryId, $row[ProductTableRow::categoryId]);
    }

    /** @return array<string, array{ProductKind}> Both catalog kinds */
    public static function kinds(): array
    {
        return [
            'stock' => [ProductKind::STOCK],
            'unique' => [ProductKind::UNIQUE],
        ];
    }

    /** The existing publication action works while Overview is open and its file becomes the new picture. */
    public function testAnOverviewCanPublishAndUseAReplacementPicture(): void
    {
        $productId = $this->holdProduct();
        $initial = $this->openOverview(self::ADMIN_KEY, $productId);
        $before = self::storedProduct($productId);
        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH, [
            ProductPicturePublishActionDTO::clientUploadId => 'vase-upload',
        ], 'publish');
        self::assertSame([
            [self::ADMIN_KEY, CatalogPictureUploadTarget::NAME, ['vase-upload'], EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISHED],
        ], $this->recorder->published);
        self::assertSame([], $this->actionFrames(self::ADMIN_KEY));

        $fileId = self::ownPictureFile($this->adminId);
        $this->asProductsLibrary(fn () => $this->library->onSignalAgent(
            new AgentSignalData(new FilesPublishedSignalData(self::ADMIN_KEY, ['vase-upload'], [$fileId], null)),
            'agent',
            EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISHED,
        ));
        [$published] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $published);
        self::assertSame(EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH, $published->action);
        self::assertSame('publish', $published->requestId);
        self::assertSame([
            'fileId' => $fileId,
            'picture' => HilosFiles::downloadPath($fileId, CatalogPictureUploadTarget::VARIANT_SM),
        ], $published->reply);

        $input = $this->input($initial);
        $input[ObjectProduct::pictureFileId] = $fileId;
        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_UPDATE, $input, 'replace');
        $this->assertSaved('replace', 'Glazed vase');
        $this->deliverProductEdit($productId, $before, 'replace');

        $card = self::sentCard(self::ADMIN_KEY);
        $this->assertEditedCard($card, $initial);
        self::assertSame($fileId, $card[ObjectProduct::pictureFileId]);
        self::assertSame(
            HilosFiles::downloadPath($fileId, CatalogPictureUploadTarget::VARIANT_LG),
            $card[AdminProductBrowserData::FIELD_PICTURE_URL],
        );
    }

    /** Only a catalog edit makes the old version stale; a new submission can use the live version. */
    public function testAStaleEditIsRefusedAndTheLiveVersionCanBeSubmittedAgain(): void
    {
        $productId = $this->holdProduct();
        $initial = $this->openOverview(self::ADMIN_KEY, $productId);
        $input = $this->input($initial);
        Hilos::$db->productWarehouseStocks->actions->setUnits($productId, self::warehouse(), 9);
        $before = self::storedProduct($productId);

        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_UPDATE, $input, 'first');
        $this->assertSaved('first', 'Glazed vase');
        $this->deliverProductEdit($productId, $before, 'first');
        $current = self::sentCard(self::ADMIN_KEY);
        $this->assertEditedCard($current, $initial);
        self::assertSame(9, $current[AdminProductBrowserData::FIELD_STOCK][AdminProductBrowserData::STOCK_UNITS]);
        $saved = self::storedProduct($productId);

        $input[ObjectProduct::name] = 'Second edit';
        $input[ObjectProduct::pictureFileId] = self::ownPictureFile($this->visitorId);
        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_UPDATE, $input, 'stale');
        self::assertSame(
            'The product changed elsewhere. Review the current values and save again.',
            $this->refusal('stale')->reason,
        );
        self::assertSame($saved, self::storedProduct($productId));

        $input = $this->input($current);
        $input[ObjectProduct::name] = 'Second edit';
        $input[ObjectProduct::categoryId] = self::category('Tableware');
        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_UPDATE, $input, 'retry');
        $this->assertSaved('retry', 'Second edit');
        $this->deliverProductEdit($productId, $saved, 'retry');
        $updated = self::sentCard(self::ADMIN_KEY);
        self::assertSame('Second edit', $updated[ObjectProduct::name]);
        self::assertSame($input[ObjectProduct::categoryId], $updated[ObjectProduct::categoryId]);
        self::assertNotSame($current[AdminProductBrowserData::FIELD_EDIT_VERSION], $updated[AdminProductBrowserData::FIELD_EDIT_VERSION]);
    }

    /** An invalid target, field, or replacement is refused without writing to the product. */
    public function testInvalidEditsWriteNothing(): void
    {
        $foreignFile = self::ownPictureFile($this->visitorId);
        $unownedFile = self::ownPictureFile($this->adminId);
        Database::sql('UPDATE hilos_file SET owner_user_id = NULL WHERE id = ?', [$unownedFile]);
        $linkedFile = self::ownPictureFile($this->adminId);
        $other = self::productIn($this->categoryId, ProductKind::UNIQUE, 'Other piece', 41000, 1795132800123);
        Database::sql('UPDATE product SET picture_file_id = ? WHERE id = ?', [$linkedFile, $other]);
        $productId = $this->holdProduct();
        $input = $this->input($this->openOverview(self::ADMIN_KEY, $productId));
        $saved = self::storedProduct($productId);

        foreach ([
            'missing product' => [AdminProductPageSubscribeParams::PRODUCT_ID, $productId + 1, 'This product no longer exists'],
            'invalid price' => [ObjectProduct::price, '2,100', 'Write at most two digits after the point'],
            'missing category' => [ObjectProduct::categoryId, $this->categoryId + 1, 'This category no longer exists'],
            'foreign picture' => [ObjectProduct::pictureFileId, $foreignFile, 'This file is gone; upload it again'],
            'unowned picture' => [ObjectProduct::pictureFileId, $unownedFile, 'This file is gone; upload it again'],
            'already linked picture' => [ObjectProduct::pictureFileId, $linkedFile, 'This file is gone; upload it again'],
            'kind supplied' => [ObjectProduct::kind, ProductKind::UNIQUE->value, 'A product keeps the kind it was created with'],
        ] as $requestId => [$field, $value, $reason]) {
            $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_UPDATE, [...$input, $field => $value], $requestId);
            self::assertSame($reason, $this->refusal($requestId)->reason);
            self::assertSame($saved, self::storedProduct($productId));
        }
    }

    /** A viewer with Overview open and the same person with view mode off both get the ordinary gate refusal. */
    public function testAViewerAndANonAdminCannotEditTheProduct(): void
    {
        $productId = $this->holdProduct();
        $input = $this->input($this->openOverview(self::VISITOR_KEY, $productId));
        $saved = self::storedProduct($productId);

        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_UPDATE, $input, 'viewer', self::VISITOR_KEY);
        self::assertSame(ActionViewModeException::ERROR_CODE, $this->refusal('viewer', self::VISITOR_KEY)->errorCode);
        self::assertSame($saved, self::storedProduct($productId));

        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_UPDATE, $input, 'non-admin', self::VISITOR_KEY);
        self::assertSame(ActionForbiddenException::ERROR_CODE, $this->refusal('non-admin', self::VISITOR_KEY)->errorCode);
        self::assertSame($saved, self::storedProduct($productId));
    }

    /**
     * @param ProductKind $kind Kind of fixture
     * @return int Product now held by the library
     */
    private function holdProduct(ProductKind $kind = ProductKind::STOCK): int
    {
        $id = self::productIn($this->categoryId, $kind, 'Blue vase', 41000, 1795132800123);
        self::holdCatalog();

        return $id;
    }

    /**
     * @param string $acceptKey Connection opening Overview
     * @param int $productId Product to open
     * @return array<string, mixed> Initial live card
     */
    private function openOverview(string $acceptKey, int $productId): array
    {
        $params = [AdminProductPageSubscribeParams::PRODUCT_ID => (string)$productId];
        Hilos::$sr->subscribeToPage(
            AdminProductPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, AdminProductPage::PAGE, $params),
        );
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params): void {
            new AdminProductPage($this->library)->onSubscribe($acceptKey, new PageRouteParams($params));
        });

        return self::sentCard($acceptKey);
    }

    /** @param int $productId Product that the observer's initial category-filtered window must contain */
    private function openFilteredProducts(int $productId): void
    {
        $windows = [ProductsTable::TABLE => new TableWindowDescriptorDTO(filter: [ProductsTable::FILTER_CATEGORY => $this->categoryId])];
        Hilos::$sr->subscribeToPage(
            AdminProductsPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::VISITOR_KEY, AdminProductsPage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows(self::VISITOR_KEY, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::VISITOR_KEY), function (): void {
            new AdminProductsPage($this->library)->onSubscribe(self::VISITOR_KEY, new PageRouteParams([]));
        });
        $response = self::takeFrames([self::VISITOR_KEY => SignalTypeConstants::PAGE_RESPONSE])[self::VISITOR_KEY];
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(AdminProductsPage::PAGE, $response->pageKey);
        $rows = $response->payload->toArray()[PagePayload::windows][ProductsTable::TABLE][TableWindowSignalData::rows];
        self::assertSame([$productId], array_column($rows, PagePayload::rowKey));
    }

    /**
     * @param array<string, mixed> $card Card the editor read
     * @return array<string, int|string|null> Catalog edit carrying the card's version
     */
    private function input(array $card): array
    {
        return [
            AdminProductPageSubscribeParams::PRODUCT_ID => $card[AdminProductBrowserData::productId],
            ProductUpdateActionDTO::expectedVersion => $card[AdminProductBrowserData::FIELD_EDIT_VERSION],
            ObjectProduct::name => '  Glazed vase  ',
            ObjectProduct::categoryId => $this->categoryId,
            ObjectProduct::price => '2 100,50',
            ObjectProduct::description => 'Hand-thrown stoneware',
            ObjectProduct::pictureFileId => null,
        ];
    }

    /**
     * Feeds the browser mirror the persisted update. The rollback root holds the outbound DB announcement.
     *
     * @param int $productId Product the action saved
     * @param array<string, mixed> $before Row before the action
     * @param string $requestId Tracked action that authored the change
     */
    private function deliverProductEdit(int $productId, array $before, string $requestId): void
    {
        $after = self::storedProduct($productId);
        $changed = array_diff_assoc($after, $before);
        self::assertNotEmpty($changed);
        Hilos::$browser->record(SourceChange::dbUpdated(
            EcommerceShopDbContext::products,
            (string)$productId,
            $changed,
            origin: self::ADMIN_KEY,
            originRequestId: $requestId,
            previous: array_intersect_key($before, $changed),
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
    }

    /**
     * @param int $productId Product to read from the database
     * @return array<string, mixed> Stored row
     */
    private static function storedProduct(int $productId): array
    {
        return Database::sql('SELECT * FROM product WHERE id = ?', [$productId])->rows()[0];
    }

    /**
     * @param string $acceptKey Product card recipient
     * @return array<string, mixed> Card in the queued page response
     */
    private static function sentCard(string $acceptKey): array
    {
        return self::cardOf(self::takeFrames([$acceptKey => SignalTypeConstants::PAGE_RESPONSE])[$acceptKey]);
    }

    /**
     * @param SignalDataInterface $response Expected product page response
     * @return array<string, mixed> Card in the page response
     */
    private static function cardOf(SignalDataInterface $response): array
    {
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(AdminProductPage::PAGE, $response->pageKey);

        return $response->payload->toArray()[PagePayload::data][AdminProductBrowserData::DATA];
    }

    /**
     * @param array<string, string> $expected Expected frame type by recipient
     * @return array<string, SignalDataInterface> Matching frame payloads by recipient
     */
    private static function takeFrames(array $expected): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (!$signal->data instanceof WebSocketSignalData) {
                continue;
            }
            $acceptKey = $signal->data->targetAcceptKey;
            if (($expected[$acceptKey] ?? null) === $signal->signalName->getName()) {
                self::assertArrayNotHasKey($acceptKey, $frames, "Duplicate frame for {$acceptKey}");
                $frames[$acceptKey] = $signal->data->data;
            }
        }
        foreach ($expected as $acceptKey => $type) {
            self::assertArrayHasKey($acceptKey, $frames, "Missing {$type} for {$acceptKey}");
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $card Live card after saving
     * @param array<string, mixed> $initial Card before editing
     */
    private function assertEditedCard(array $card, array $initial): void
    {
        self::assertSame($initial[AdminProductBrowserData::productId], $card[AdminProductBrowserData::productId]);
        self::assertSame('Glazed vase', $card[ObjectProduct::name]);
        self::assertSame(210050, $card[ObjectProduct::price]);
        self::assertSame('Hand-thrown stoneware', $card[ObjectProduct::description]);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $card[AdminProductBrowserData::FIELD_EDIT_VERSION]);
        self::assertNotSame($initial[AdminProductBrowserData::FIELD_EDIT_VERSION], $card[AdminProductBrowserData::FIELD_EDIT_VERSION]);
    }

    /**
     * @param string $requestId Expected request correlation
     * @param string $savedName Name stored for the product
     */
    private function assertSaved(string $requestId, string $savedName): void
    {
        [$reply] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertSame(EcommerceShopSignalConstants::PRODUCT_UPDATE, $reply->action);
        self::assertSame($requestId, $reply->requestId);
        self::assertSame("Product “{$savedName}” saved.", $reply->message);
        self::assertNull($reply->reply);
    }

    /**
     * @param string $requestId Expected request correlation
     * @param string $acceptKey Connection that sent the edit
     * @return PageActionErrorSignalData Correlated refusal
     */
    private function refusal(string $requestId, string $acceptKey = self::ADMIN_KEY): PageActionErrorSignalData
    {
        [$error] = $this->actionFrames($acceptKey, 1);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame(EcommerceShopSignalConstants::PRODUCT_UPDATE, $error->action);
        self::assertSame($requestId, $error->requestId);

        return $error;
    }
}
