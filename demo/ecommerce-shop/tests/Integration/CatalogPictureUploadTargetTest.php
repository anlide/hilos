<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Files\Image\ImageCopy;
use Hilos\Files\Image\ImageVariant;
use Hilos\HilosException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration tests for the shop's catalog picture upload target (HIL-2155).
 *
 * Only an administrator declares a catalog picture, and each of the four declared copies is
 * drawn from a JPEG and from a PNG original at exactly its frame. The whole way from an upload
 * to a served copy is the framework's and is proven in the framework's own suite; this case
 * proves the shop's policy and its four declarations on top of it.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class CatalogPictureUploadTargetTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** Width of the original; wider than every frame and, with the height, not 4:3. */
    private const int ORIGINAL_WIDTH = 1600;

    private const int ORIGINAL_HEIGHT = 1000;

    /** File each case draws its copy into, removed after the case. */
    private ?string $copyPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        if ($this->copyPath !== null && is_file($this->copyPath)) {
            unlink($this->copyPath);
        }
        parent::tearDown();
    }

    /**
     * An administrator's connection declares a catalog picture.
     *
     * @throws HilosException On database or runtime error
     */
    public function testAnAdministratorMayUploadACatalogPicture(): void
    {
        $adminId = (int) Hilos::$db->users->actions->registerAdmin()->id;
        Hilos::$rt->connections->actions->register('catalog-picture-admin-ak', $adminId);

        new CatalogPictureUploadTarget()->assertBrowserMutationAllowed('catalog-picture-admin-ak', 'catalog-picture-1');

        $this->addToAssertionCount(1);
    }

    /**
     * A signed-in person who is not an administrator is refused with the target's own words.
     *
     * @throws HilosException On database or runtime error
     */
    public function testASignedInCustomerIsForbidden(): void
    {
        $userId = (int) Hilos::$db->users->actions->createWithName('Catalog Customer')->id;
        Hilos::$rt->connections->actions->register('catalog-picture-customer-ak', $userId);

        $this->expectException(ActionForbiddenException::class);
        $this->expectExceptionMessage('Only an administrator can upload catalog pictures');

        new CatalogPictureUploadTarget()->assertBrowserMutationAllowed('catalog-picture-customer-ak', 'catalog-picture-1');
    }

    /**
     * A connection with nobody signed in behind it is refused as unauthenticated.
     *
     * @throws HilosException On runtime error
     */
    public function testAConnectionWithoutAPersonIsUnauthorized(): void
    {
        Hilos::$rt->connections->actions->register('catalog-picture-guest-ak', null);

        $this->expectException(ActionUnauthorizedException::class);

        new CatalogPictureUploadTarget()->assertBrowserMutationAllowed('catalog-picture-guest-ak', 'catalog-picture-1');
    }

    /**
     * @return array<string, array{string, int, int, string}> Variant, its frame and the original's type
     */
    public static function variantsAndOriginals(): array
    {
        $cases = [];
        foreach ([
            CatalogPictureUploadTarget::VARIANT_XS => [72, 54],
            CatalogPictureUploadTarget::VARIANT_SM => [128, 96],
            CatalogPictureUploadTarget::VARIANT_TILE => [840, 630],
            CatalogPictureUploadTarget::VARIANT_LG => [1200, 900],
        ] as $variant => [$width, $height]) {
            foreach (['image/jpeg', 'image/png'] as $mimeType) {
                $cases["{$variant} from {$mimeType}"] = [$variant, $width, $height, $mimeType];
            }
        }

        return $cases;
    }

    /**
     * Each declared copy is a WebP cut to exactly its frame, the centre of the wider original.
     *
     * @throws HilosException When the copy cannot be drawn or written
     */
    #[DataProvider('variantsAndOriginals')]
    public function testEveryVariantIsDrawnAtItsFrame(string $variantName, int $width, int $height, string $mimeType): void
    {
        $variant = ImageVariant::named($variantName);
        self::assertNotNull($variant);
        $copyPath = tempnam(sys_get_temp_dir(), 'catalog-picture-');
        self::assertIsString($copyPath);
        $this->copyPath = $copyPath;

        $written = ImageCopy::draw(ImageCopy::engine(), self::original($mimeType), $variant, $this->copyPath);

        self::assertSame(filesize($this->copyPath), $written);
        $size = getimagesize($this->copyPath);
        self::assertIsArray($size);
        self::assertSame('image/webp', $size['mime']);
        self::assertSame($width, $size[0]);
        self::assertSame($height, $size[1]);
    }

    /**
     * Builds a two-color original of the given type, so no fixture file is needed.
     *
     * @param string $mimeType image/jpeg or image/png
     * @return string Encoded original
     */
    private static function original(string $mimeType): string
    {
        $image = imagecreatetruecolor(self::ORIGINAL_WIDTH, self::ORIGINAL_HEIGHT);
        self::assertNotFalse($image);
        imagefilledrectangle(
            $image,
            0,
            0,
            intdiv(self::ORIGINAL_WIDTH, 2),
            self::ORIGINAL_HEIGHT,
            (int) imagecolorallocate($image, 200, 40, 40),
        );
        imagefilledrectangle(
            $image,
            intdiv(self::ORIGINAL_WIDTH, 2),
            0,
            self::ORIGINAL_WIDTH,
            self::ORIGINAL_HEIGHT,
            (int) imagecolorallocate($image, 40, 40, 200),
        );
        ob_start();
        if ($mimeType === 'image/jpeg') {
            imagejpeg($image);
        } else {
            imagepng($image);
        }
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }
}
