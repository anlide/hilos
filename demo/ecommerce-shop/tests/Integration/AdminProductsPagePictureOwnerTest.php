<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/** The create action rechecks picture ownership while the loaded Files row still shows the publisher. */
#[IntegrationTest]
final class AdminProductsPagePictureOwnerTest extends AdminProductsPageActionEnvironment implements BreaksOrmState
{
    /** The catalog has a category and the page has an administrator and a non-admin connected. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $this->categoryId = self::category();
        self::holdCatalog();
        $this->connectProductPageUsers();
    }

    /** The write must reject a registry owner change even when the browser precheck sees the old owner. */
    public function testCreateRechecksThePublisherOfTheLockedFile(): void
    {
        $input = $this->productInput('Blue vase');
        $fileId = $input[ObjectProduct::pictureFileId];
        self::assertSame($this->adminId, Hilos::$db->files[$fileId]->ownerUserId);
        Database::sql('UPDATE hilos_file SET owner_user_id = ? WHERE id = ?', [$this->visitorId, $fileId]);
        self::assertSame($this->adminId, Hilos::$db->files[$fileId]->ownerUserId);
        self::assertSame($this->visitorId, (int)Database::sql('SELECT owner_user_id FROM hilos_file WHERE id = ?', [$fileId])
            ->field('owner_user_id'));

        $this->dispatchAction(EcommerceShopSignalConstants::PRODUCT_CREATE, $input, 'req-create');

        [$refusal] = $this->actionFrames(self::ADMIN_KEY, 1);
        self::assertInstanceOf(PageActionErrorSignalData::class, $refusal);
        self::assertSame('req-create', $refusal->requestId);
        self::assertSame('This file is gone; upload it again', $refusal->reason);
        self::assertSame([], Database::sql('SELECT id FROM product WHERE picture_file_id = ?', [$fileId])->rows());
    }
}
