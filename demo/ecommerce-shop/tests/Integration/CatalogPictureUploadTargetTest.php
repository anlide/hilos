<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Hilos\Files\Image\ImageCopy;
use Hilos\Files\Image\ImageVariant;
use Hilos\HilosException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration tests for the shop's catalog picture upload target (HIL-2155).
 *
 * Each of the four declared copies is drawn from a JPEG and from a PNG original at exactly its
 * frame. The whole way from an upload to a served copy is the framework's and is proven in the
 * framework's own suite; this case proves the shop's four declarations on top of it. Who may
 * upload is the target's access level, checked by EcommerceShopTopologyRegistryTest.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class CatalogPictureUploadTargetTest extends IntegrationTestCase
{
    /** Width of the original; wider than every frame and, with the height, not 4:3. */
    private const int ORIGINAL_WIDTH = 1600;

    private const int ORIGINAL_HEIGHT = 1000;

    /** File each case draws its copy into, removed after the case. */
    private ?string $copyPath = null;

    protected function tearDown(): void
    {
        if ($this->copyPath !== null && is_file($this->copyPath)) {
            unlink($this->copyPath);
        }
        parent::tearDown();
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
