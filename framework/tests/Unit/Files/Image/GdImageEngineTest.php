<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Image;

use GdImage;
use Hilos\Files\Image\GdImageEngine;
use Hilos\Files\Image\ImageFit;
use Hilos\Files\Image\ImageFormat;
use Hilos\Files\Image\ImageProbe;
use Hilos\Files\Image\ImageRenderException;
use Hilos\Files\Image\ImageVariant;
use Hilos\Fs\Exception\FileWriteException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real GD pixels: geometry, all eight orientations, transparency and broken input. */
final class GdImageEngineTest extends TestCase
{
    private string $target;

    /** A real output file lets the engine report the bytes it encoded. */
    protected function setUp(): void
    {
        self::assertTrue(extension_loaded('gd'), 'Build the framework test image with GD');
        $target = tempnam(sys_get_temp_dir(), 'hilos-variant-');
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

    /** @return iterable<string, array{int, int, int, int, ImageFit, int, int}> Source, frame and expected output */
    public static function geometry(): iterable
    {
        yield 'contain landscape' => [4000, 3000, 384, 384, ImageFit::CONTAIN, 384, 288];
        yield 'cover same ratio' => [4000, 3000, 800, 600, ImageFit::COVER, 800, 600];
        yield 'cover square' => [4000, 3000, 300, 300, ImageFit::COVER, 300, 300];
        yield 'contain portrait' => [30, 40, 12, 12, ImageFit::CONTAIN, 9, 12];
        yield 'contain never enlarges' => [20, 10, 384, 384, ImageFit::CONTAIN, 20, 10];
        yield 'cover never enlarges' => [20, 10, 384, 384, ImageFit::COVER, 10, 10];
        yield 'thin source' => [1, 40, 1, 1, ImageFit::CONTAIN, 1, 1];
    }

    /**
     * @param int $sourceWidth Source width
     * @param int $sourceHeight Source height
     * @param int $width Frame width
     * @param int $height Frame height
     * @param ImageFit $fit Fit mode
     * @param int $expectedWidth Result width
     * @param int $expectedHeight Result height
     */
    #[DataProvider('geometry')]
    public function testGeometry(
        int $sourceWidth,
        int $sourceHeight,
        int $width,
        int $height,
        ImageFit $fit,
        int $expectedWidth,
        int $expectedHeight,
    ): void {
        $bytes = self::png(imagecreatetruecolor($sourceWidth, $sourceHeight));
        $engine = new GdImageEngine();
        self::assertNull($engine->unusableReason(ImageFormat::cases()));
        $probe = $engine->probe($bytes);
        self::assertNotNull($probe);
        $size = $engine->render($bytes, $probe, 1, new ImageVariant('thumb', $width, $height, $fit, ImageFormat::WEBP), $this->target);
        self::assertSame(filesize($this->target), $size);
        $result = getimagesize($this->target);
        self::assertNotFalse($result);
        self::assertSame([$expectedWidth, $expectedHeight, 'image/webp'], [$result[0], $result[1], $result['mime']]);
    }

    /** @return iterable<string, array{int, list<list<int>>}> EXIF orientation and expected labeled pixel matrix */
    public static function orientations(): iterable
    {
        yield 'normal' => [1, [[1, 2, 3], [4, 5, 6]]];
        yield 'horizontal mirror' => [2, [[3, 2, 1], [6, 5, 4]]];
        yield 'half turn' => [3, [[6, 5, 4], [3, 2, 1]]];
        yield 'vertical mirror' => [4, [[4, 5, 6], [1, 2, 3]]];
        yield 'transpose' => [5, [[1, 4], [2, 5], [3, 6]]];
        yield 'clockwise' => [6, [[4, 1], [5, 2], [6, 3]]];
        yield 'transverse' => [7, [[6, 3], [5, 2], [4, 1]]];
        yield 'counterclockwise' => [8, [[3, 6], [2, 5], [1, 4]]];
    }

    /**
     * @param int $orientation EXIF orientation
     * @param list<list<int>> $expected Expected red-channel labels after turning upright
     */
    #[DataProvider('orientations')]
    public function testEveryOrientation(int $orientation, array $expected): void
    {
        $source = imagecreatetruecolor(3, 2);
        foreach ([[1, 2, 3], [4, 5, 6]] as $y => $row) {
            foreach ($row as $x => $value) {
                imagesetpixel($source, $x, $y, imagecolorallocate($source, $value, 0, 0));
            }
        }
        $result = $this->renderPng(self::png($source), $orientation);
        self::assertSame(count($expected), imagesy($result));
        self::assertSame(count($expected[0]), imagesx($result));
        foreach ($expected as $y => $row) {
            foreach ($row as $x => $value) {
                self::assertSame($value, imagecolorsforindex($result, imagecolorat($result, $x, $y))['red']);
            }
        }
    }

    /** The cover crop uses the center, rather than the first corner that fits. */
    public function testCoverTakesTheCenter(): void
    {
        $source = imagecreatetruecolor(6, 2);
        imagefill($source, 0, 0, imagecolorallocate($source, 255, 0, 0));
        imagefilledrectangle($source, 2, 0, 3, 1, imagecolorallocate($source, 0, 255, 0));
        $bytes = self::png($source);
        $engine = new GdImageEngine();
        $probe = $engine->probe($bytes);
        self::assertNotNull($probe);
        $engine->render($bytes, $probe, 1, new ImageVariant('square', 2, 2, ImageFit::COVER, ImageFormat::PNG), $this->target);
        $result = imagecreatefrompng($this->target);
        self::assertInstanceOf(GdImage::class, $result);
        self::assertSame(255, imagecolorsforindex($result, imagecolorat($result, 0, 0))['green']);
        self::assertSame(255, imagecolorsforindex($result, imagecolorat($result, 1, 1))['green']);
    }

    /** Alpha survives PNG and WEBP; JPEG composites it onto white. */
    public function testTransparencyAndJpegBackground(): void
    {
        $source = imagecreatetruecolor(8, 8);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
        $bytes = self::png($source);
        $engine = new GdImageEngine();
        $probe = $engine->probe($bytes);
        self::assertNotNull($probe);
        foreach (ImageFormat::cases() as $format) {
            $engine->render($bytes, $probe, 1, new ImageVariant('transparent', 8, 8, ImageFit::CONTAIN, $format), $this->target);
            $result = imagecreatefromstring(file_get_contents($this->target));
            self::assertInstanceOf(GdImage::class, $result);
            $color = imagecolorsforindex($result, imagecolorat($result, 0, 0));
            if ($format === ImageFormat::JPEG) {
                self::assertGreaterThanOrEqual(250, $color['red']);
                self::assertGreaterThanOrEqual(250, $color['green']);
                self::assertGreaterThanOrEqual(250, $color['blue']);
            } else {
                self::assertSame(127, $color['alpha']);
            }
        }
    }

    /** Reading an oversized header never requires decoding its pixel array. */
    public function testProbeDoesNotDecodePixels(): void
    {
        $header = pack('NNCCCCC', 10000, 5000, 8, 6, 0, 0, 0);
        $bytes = "\x89PNG\r\n\x1a\n" . pack('N', strlen($header)) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header));
        $probe = new GdImageEngine()->probe($bytes);
        self::assertNotNull($probe);
        self::assertSame([10000, 5000], [$probe->width, $probe->height]);
        self::assertNull(new GdImageEngine()->probe('not an image'));
        self::assertNull(new GdImageEngine()->probe(''));
    }

    /** The worker's outer error handler is restored on both success and refusal. */
    public function testBrokenBytesRaiseAValidationFailureAndRestoreTheHandler(): void
    {
        $handler = static fn(): bool => false;
        set_error_handler($handler);
        try {
            try {
                new GdImageEngine()->render('broken', new ImageProbe('image/png', 1, 1), 1,
                    new ImageVariant('bad', 1, 1, ImageFit::CONTAIN, ImageFormat::PNG), $this->target);
                self::fail('Broken bytes must not become a successful rendering');
            } catch (ImageRenderException) {
                self::assertSame($handler, set_error_handler($handler));
                restore_error_handler();
            }
        } finally {
            restore_error_handler();
        }
    }

    /** Failed writes are retryable, and their warning never reaches the worker's outer handler. */
    public function testUnwritableCopyRaisesAFileFailureAndRestoresTheHandler(): void
    {
        $bytes = self::png(imagecreatetruecolor(4, 4));
        $engine = new GdImageEngine();
        $probe = $engine->probe($bytes);
        self::assertNotNull($probe);
        $handler = static fn(): bool => false;
        set_error_handler($handler);
        try {
            foreach (ImageFormat::cases() as $format) {
                try {
                    $engine->render($bytes, $probe, 1, new ImageVariant('copy', 2, 2, ImageFit::CONTAIN, $format),
                        $this->target . '/missing/copy');
                    self::fail('An unwritable copy must not become a picture refusal');
                } catch (FileWriteException $e) {
                    self::assertStringStartsWith('Cannot write the image copy:', $e->getMessage());
                    self::assertSame($handler, set_error_handler($handler));
                    restore_error_handler();
                }
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param string $bytes PNG source bytes
     * @param int $orientation EXIF orientation
     * @return GdImage Decoded output of the renderer
     */
    private function renderPng(string $bytes, int $orientation): GdImage
    {
        $engine = new GdImageEngine();
        $probe = $engine->probe($bytes);
        self::assertNotNull($probe);
        $engine->render($bytes, $probe, $orientation, new ImageVariant('pixels', 10, 10, ImageFit::CONTAIN, ImageFormat::PNG), $this->target);
        $result = imagecreatefrompng($this->target);
        self::assertInstanceOf(GdImage::class, $result);

        return $result;
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
