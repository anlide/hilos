<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Hilos;
use Hilos\Database\Entity\Item\File;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/** Models a changed publisher on PRIMARY while the categories library still holds the old file row. */
#[IntegrationTest]
final class AdminCategoriesPagePictureOwnerTest extends AdminCategoriesPageEnvironment implements BreaksOrmState
{
    /** A replacement is refused at the write when its publisher differs from the publisher seen in the precheck. */
    public function testUpdateRechecksThePublisherOfTheLockedFile(): void
    {
        $baseline = $this->storedCategory();
        $fileId = $this->ownPictureFile($this->adminId, false);
        self::assertSame($this->adminId, Hilos::$db->files[$fileId]->ownerUserId);
        Database::sql('UPDATE hilos_file SET owner_user_id = ? WHERE id = ?', [$this->visitorId, $fileId]);
        self::assertSame($this->adminId, Hilos::$db->files[$fileId]->ownerUserId);

        $refusal = $this->refusedUpdate($this->updatePayload([ObjectCategory::pictureFileId => $fileId]));

        self::assertSame('This file is gone; upload it again', $refusal->reason);
        self::assertSame($baseline, $this->storedCategory());
        $stored = Database::sql('SELECT owner_user_id, bound FROM hilos_file WHERE id = ?', [$fileId])->row();
        self::assertNotNull($stored);
        self::assertSame($this->visitorId, (int)$stored[File::owner_user_id]);
        self::assertSame(0, (int)$stored[File::bound]);
    }
}
