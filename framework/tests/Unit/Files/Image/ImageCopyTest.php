<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Image;

use GdImage;
use Hilos\Files\Image\GdImageEngine;
use Hilos\Files\Image\ImageCopy;
use Hilos\Files\Image\ImageFit;
use Hilos\Files\Image\ImageFormat;
use Hilos\Files\Image\ImageRenderException;
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Image\ImageVariant;
use PHPUnit\Framework\TestCase;

/** The shared copy step: contained geometry, bytes that are not a picture, and a header over the ceiling. */
final class ImageCopyTest extends TestCase
{
    private string $target;

    /** A real output file lets the copy step report the bytes it encoded. */
    protected function setUp(): void
    {
        self::assertTrue(extension_loaded('gd'), 'Build the framework test image with GD');
        self::assertSame(ImageCopy::MAX_PIXELS, ImagesAgent::MAX_PIXELS);
        $target = tempnam(sys_get_temp_dir(), 'hilos-image-copy-');
        self::assertNotFalse($target);
        $this->target = $target;
    }

    /** Removes the output even when an assertion fails. */
    protected function tearDown(): void
    {
        if (isset($this->target) && is_file($this->target)) {
            unlink($this->target);
        }
    }

    /** A 1000×600 PNG contained in a 512×512 JPEG frame keeps its long side and proportions. */
    public function testContainJpegKeepsTheLongSideAndProportions(): void
    {
        $source = imagecreatetruecolor(1000, 600);
        self::assertInstanceOf(GdImage::class, $source);
        $size = ImageCopy::draw(new GdImageEngine(), self::png($source),
            new ImageVariant('frame', 512, 512, ImageFit::CONTAIN, ImageFormat::JPEG), $this->target);
        self::assertSame(filesize($this->target), $size);
        $result = getimagesize($this->target);
        self::assertNotFalse($result);
        self::assertSame([512, 307, 'image/jpeg'], [$result[0], $result[1], $result['mime']]);
    }

    /** Bytes the engine cannot read as a picture are refused before a copy is encoded. */
    public function testBytesThatAreNotAPictureAreRefused(): void
    {
        $this->expectException(ImageRenderException::class);
        $this->expectExceptionMessage('not a picture the engine reads');
        ImageCopy::draw(new GdImageEngine(), 'not a picture',
            new ImageVariant('frame', 512, 512, ImageFit::CONTAIN, ImageFormat::JPEG), $this->target);
    }

    /** A header over the pixel ceiling is refused without decoding its pixels. */
    public function testHeaderAboveThePixelCeilingIsRefused(): void
    {
        $header = pack('NNCCCCC', 10000, 5000, 8, 6, 0, 0, 0);
        $bytes = "\x89PNG\r\n\x1a\n" . pack('N', strlen($header)) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header));
        $this->expectException(ImageRenderException::class);
        $this->expectExceptionMessage('10000x5000 is above the ' . ImageCopy::MAX_PIXELS . '-pixel ceiling');
        ImageCopy::draw(new GdImageEngine(), $bytes,
            new ImageVariant('frame', 512, 512, ImageFit::CONTAIN, ImageFormat::JPEG), $this->target);
    }

    /**
     * @param GdImage $image Fixture image
     * @return string Encoded PNG bytes
     */
    private static function png(GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }
}
