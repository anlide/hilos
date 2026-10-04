<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Metadata;

use FilesystemIterator;
use Hilos\Files\Image\JpegOrientation;
use Hilos\Files\Metadata\ExifOrientation;
use Hilos\Files\Metadata\ImageMetadataOutcome;
use Hilos\Files\Metadata\ImageMetadataStripper;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsPath;
use Hilos\Fs\FsTmpDirectory;
use PHPUnit\Framework\TestCase;

/** Exercises container bytes, retained rendering data, and the output fingerprint. */
final class ImageMetadataStripperTest extends TestCase
{
    private string $directory;
    private FsTmpDirectory $tmp;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/hilos-metadata-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->tmp = new FsTmpDirectory($this->directory, DirectoryScope::NODE);
    }

    protected function tearDown(): void
    {
        foreach (new FilesystemIterator($this->directory) as $file) {
            unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testJpegDropsPrivateMarkersAndTailWhileKeepingOrientationAndColor(): void
    {
        $scan = self::scan("abc\xff\x00x\xff\xd0def");
        $input = "\xff\xd8"
            . self::marker(0xe0, "JFIF\0public")
            . self::marker(0xe1, "Exif\0\0" . self::tiffWithGps(6))
            . self::marker(0xe1, "http://ns.adobe.com/xap/1.0/\0PRIVATE-XMP")
            . self::marker(0xed, 'PRIVATE-IPTC')
            . self::marker(0xfe, 'PRIVATE-COMMENT')
            . self::marker(0xe0, "JFXX\0PRIVATE-THUMB")
            . self::marker(0xe2, "ICC_PROFILE\0part-one")
            . self::marker(0xe2, "ICC_PROFILE\0part-two")
            . self::marker(0xee, 'Adobe color')
            . $scan . "\xff\xd9PRIVATE-TAIL";
        $expected = "\xff\xd8" . self::marker(0xe0, "JFIF\0public")
            . self::marker(0xe1, "Exif\0\0" . ExifOrientation::tiff(6))
            . self::marker(0xe2, "ICC_PROFILE\0part-one")
            . self::marker(0xe2, "ICC_PROFILE\0part-two")
            . self::marker(0xee, 'Adobe color') . $scan . "\xff\xd9";

        $this->assertStripped($input, $expected);
        self::assertSame(6, JpegOrientation::read($expected));
    }

    public function testProgressiveJpegRemovesMetadataBetweenScansAndKeepsEntropy(): void
    {
        $first = self::scan("first\xff\x00byte\xff\xd3rst");
        $second = self::scan('second');
        $input = "\xff\xd8" . $first . self::marker(0xe1, 'PRIVATE-XMP')
            . self::marker(0xc4, 'DHT') . $second . "\xff\xd9";
        $expected = "\xff\xd8" . $first . self::marker(0xc4, 'DHT') . $second . "\xff\xd9";

        $this->assertStripped($input, $expected);
    }

    public function testJpegOrientationOneIsRemovedAndTruncatedFinalScanIsKept(): void
    {
        $input = "\xff\xd8" . self::marker(0xe1, "Exif\0\0" . ExifOrientation::tiff(1)) . self::scan('unfinished');
        $this->assertStripped($input, "\xff\xd8" . self::scan('unfinished'));
        $this->assertOutcome("\xff\xd8" . self::scan('unchanged'), ImageMetadataOutcome::NOTHING_TO_STRIP);
        $this->assertOutcome("\xff\xd8\xff\xe1\xff\xffbroken", ImageMetadataOutcome::MALFORMED);
    }

    public function testPngDropsAncillaryMetadataAndRewritesExifCrc(): void
    {
        $signature = "\x89PNG\r\n\x1a\n";
        $image = self::pngChunk('IHDR', str_repeat("\0", 13));
        $rendering = self::pngChunk('iCCP', 'color') . self::pngChunk('gAMA', 'gamma')
            . self::pngChunk('pHYs', 'resolution') . self::pngChunk('acTL', 'animation')
            . self::pngChunk('fcTL', 'frame') . self::pngChunk('fdAT', 'data');
        $pixels = self::pngChunk('IDAT', 'pixels') . self::pngChunk('IEND', '');
        $input = $signature . $image . self::pngChunk('tEXt', 'PRIVATE-TEXT')
            . self::pngChunk('zTXt', 'PRIVATE-ZIP') . self::pngChunk('iTXt', 'PRIVATE-XMP')
            . self::pngChunk('tIME', 'PRIVATE-TIME') . self::pngChunk('aaAa', 'PRIVATE-CHUNK')
            . self::pngChunk('eXIf', self::tiffWithGps(6)) . $rendering . $pixels . 'PRIVATE-TAIL';
        $expected = $signature . $image . self::pngChunk('eXIf', ExifOrientation::tiff(6)) . $rendering . $pixels;

        $this->assertStripped($input, $expected);
        $this->assertOutcome($signature . $image . $pixels, ImageMetadataOutcome::NOTHING_TO_STRIP);
    }

    public function testWebpRecomputesRiffSizeAndFlagsButKeepsPads(): void
    {
        $vp8x = self::webpChunk('VP8X', "\x0c" . str_repeat("\0", 9));
        $input = self::webp($vp8x . self::webpChunk('ICCP', 'ICC')
            . self::webpChunk('EXIF', self::tiffWithGps(3))
            . self::webpChunk('XMP ', 'PRIVATE-XMP')
            . self::webpChunk('JUNK', 'odd')
            . self::webpChunk('VP8 ', 'pixels')) . 'PRIVATE-TAIL';
        $expected = self::webp(self::webpChunk('VP8X', "\x08" . str_repeat("\0", 9))
            . self::webpChunk('ICCP', 'ICC')
            . self::webpChunk('EXIF', ExifOrientation::tiff(3))
            . self::webpChunk('VP8 ', 'pixels'));

        $this->assertStripped($input, $expected);
        $this->assertOutcome(self::webp(self::webpChunk('VP8 ', 'pixels')), ImageMetadataOutcome::NOTHING_TO_STRIP);
    }

    public function testUnsupportedAndMalformedInputsLeaveNoNewTemporaryFile(): void
    {
        $this->assertOutcome('plain text', ImageMetadataOutcome::NOT_AN_IMAGE);
        $this->assertOutcome('GIF89a' . str_repeat("\0", 20), ImageMetadataOutcome::NOT_AN_IMAGE);
        $this->assertOutcome("\x89PNG\r\n\x1a\n" . pack('N', 100) . 'IDAT', ImageMetadataOutcome::MALFORMED);
        $this->assertOutcome('RIFF' . pack('V', 20) . 'WEBPVP8X' . pack('V', 10), ImageMetadataOutcome::MALFORMED);
    }

    public function testARealJpegStillDecodesAndRetainsItsOrientation(): void
    {
        $image = imagecreatetruecolor(4, 3);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $input = substr($jpeg, 0, 2) . self::marker(0xe1, "Exif\0\0" . self::tiffWithGps(6)) . substr($jpeg, 2);
        $path = $this->directory . '/source';
        FsPath::write($path, $input);

        $result = ImageMetadataStripper::strip($path, $this->tmp);
        self::assertSame(ImageMetadataOutcome::STRIPPED, $result->outcome);
        $output = FsPath::read($this->tmp[$result->tmpIndex]->getPath());
        self::assertNotFalse(imagecreatefromstring($output));
        self::assertSame(6, JpegOrientation::read($output));
        self::assertStringNotContainsString('GPS-SECRET', $output);
    }

    /**
     * @param string $input Encoded original
     * @param string $expected Exact cleaned bytes
     */
    private function assertStripped(string $input, string $expected): void
    {
        $path = $this->directory . '/source';
        FsPath::write($path, $input);
        $result = ImageMetadataStripper::strip($path, $this->tmp);
        self::assertSame(ImageMetadataOutcome::STRIPPED, $result->outcome);
        self::assertSame($input, FsPath::read($path));
        $outputPath = $this->tmp[$result->tmpIndex]->getPath();
        self::assertSame($expected, FsPath::read($outputPath));
        self::assertSame(hash('sha256', $expected), $result->contentHash);
        self::assertSame(ImageMetadataOutcome::NOTHING_TO_STRIP, ImageMetadataStripper::strip($outputPath, $this->tmp)->outcome);
    }

    /**
     * @param string $input Encoded original
     * @param ImageMetadataOutcome $expected Expected classification
     */
    private function assertOutcome(string $input, ImageMetadataOutcome $expected): void
    {
        $path = $this->directory . '/source';
        FsPath::write($path, $input);
        $before = iterator_count(new FilesystemIterator($this->directory));
        $result = ImageMetadataStripper::strip($path, $this->tmp);
        self::assertSame($expected, $result->outcome, $result->reason);
        self::assertSame($before, iterator_count(new FilesystemIterator($this->directory)));
        self::assertSame($input, FsPath::read($path));
    }

    /** @return string TIFF with a GPS IFD and a marker that must disappear */
    private static function tiffWithGps(int $orientation): string
    {
        $header = 'II' . pack('vV', 42, 8);
        $orientationEntry = pack('vvVvv', 0x0112, 3, 1, $orientation, 0);
        $gpsEntry = pack('vvVV', 0x8825, 4, 1, 38);
        $gpsIfd = pack('v', 1) . pack('vvVV', 1, 2, 11, 56) . pack('V', 0) . 'GPS-SECRET';

        return $header . pack('v', 2) . $orientationEntry . $gpsEntry . pack('V', 0) . $gpsIfd;
    }

    /** @return string JPEG marker with its two-byte length */
    private static function marker(int $marker, string $data): string
    {
        return "\xff" . chr($marker) . pack('n', strlen($data) + 2) . $data;
    }

    /** @return string JPEG scan marker, header, and entropy bytes */
    private static function scan(string $data): string
    {
        return "\xff\xda\x00\x02" . $data;
    }

    /** @return string PNG chunk with a correct CRC */
    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . hex2bin(hash('crc32b', $type . $data));
    }

    /** @return string WebP chunk with its RIFF pad */
    private static function webpChunk(string $type, string $data): string
    {
        return $type . pack('V', strlen($data)) . $data . (strlen($data) % 2 === 0 ? '' : "\0");
    }

    /** @return string Complete WebP RIFF container */
    private static function webp(string $chunks): string
    {
        return 'RIFF' . pack('V', strlen($chunks) + 4) . 'WEBP' . $chunks;
    }
}
