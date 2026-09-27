<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Image;

use Hilos\Files\Image\JpegOrientation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises EXIF's two byte orders and truncated untrusted metadata. */
final class JpegOrientationTest extends TestCase
{
    /** @return iterable<string, array{string, int}> Encoded JPEG headers and their orientation */
    public static function orientations(): iterable
    {
        foreach ([true, false] as $little) {
            for ($orientation = 1; $orientation <= 8; $orientation++) {
                $tiff = ($little ? 'II' : 'MM') . pack($little ? 'vV' : 'nN', 42, 8)
                    . pack($little ? 'vvvVv' : 'nnnNn', 1, 0x0112, 3, 1, $orientation) . str_repeat("\0", 6);
                yield ($little ? 'II ' : 'MM ') . $orientation => [self::jpeg($tiff), $orientation];
            }
        }
    }

    /**
     * @param string $jpeg JPEG bytes
     * @param int $expected EXIF orientation
     */
    #[DataProvider('orientations')]
    public function testBothByteOrders(string $jpeg, int $expected): void
    {
        self::assertSame($expected, JpegOrientation::read($jpeg));
    }

    /** Every truncated prefix is harmless, including a valid APP1 with an invalid TIFF offset. */
    public function testBrokenOrAbsentMetadataMeansNormalOrientation(): void
    {
        $tiff = 'II' . pack('vVvvvVv', 42, 8, 1, 0x0112, 3, 1, 6) . str_repeat("\0", 6);
        $jpeg = self::jpeg($tiff);
        for ($length = 0; $length < strlen($jpeg) - 2; $length++) {
            self::assertSame(1, JpegOrientation::read(substr($jpeg, 0, $length)), 'prefix ' . $length);
        }
        foreach ([
            'not a JPEG', "\xff\xd8\xff\xd9", "\xff\xd8\xff\xdaExif\0\0",
            self::jpeg('II' . pack('vV', 42, 0xffffffff)),
            self::jpeg('II' . pack('vV', 41, 8)),
            self::jpeg('ZZ' . substr($tiff, 2)),
            self::jpeg('II' . pack('vVvvvVv', 42, 8, 1, 0x0112, 3, 1, 9) . str_repeat("\0", 6)),
            self::jpeg('II' . pack('vVvvvVv', 42, 8, 1, 0x0112, 4, 1, 6) . str_repeat("\0", 6)),
        ] as $bad) {
            self::assertSame(1, JpegOrientation::read($bad));
        }
    }

    /** A non-EXIF APP1, such as XMP, must not hide a later EXIF segment. */
    public function testSkipsOtherJpegSegments(): void
    {
        $tiff = 'MM' . pack('nNnnnNn', 42, 8, 1, 0x0112, 3, 1, 8) . str_repeat("\0", 6);
        self::assertSame(8, JpegOrientation::read("\xff\xd8\xff\xe1\x00\x06XMP!" . substr(self::jpeg($tiff), 2)));
    }

    /**
     * @param string $tiff TIFF payload
     * @return string JPEG with one EXIF APP1
     */
    private static function jpeg(string $tiff): string
    {
        $exif = "Exif\0\0" . $tiff;

        return "\xff\xd8\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . "\xff\xd9";
    }
}
