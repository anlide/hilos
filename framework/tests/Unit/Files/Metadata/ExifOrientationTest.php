<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Metadata;

use Hilos\Files\Image\JpegOrientation;
use Hilos\Files\Metadata\ExifOrientation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The minimal TIFF the stripper writes reads back as the orientation it was written with. */
final class ExifOrientationTest extends TestCase
{
    /**
     * @return array<string, array{0: int}> Every orientation the stripper keeps, NORMAL being dropped
     */
    public static function keptOrientations(): array
    {
        return [
            'mirror horizontal' => [JpegOrientation::MIRROR_HORIZONTAL],
            'rotate 180' => [JpegOrientation::ROTATE_180],
            'mirror vertical' => [JpegOrientation::MIRROR_VERTICAL],
            'transpose' => [JpegOrientation::TRANSPOSE],
            'rotate 90' => [JpegOrientation::ROTATE_90],
            'transverse' => [JpegOrientation::TRANSVERSE],
            'rotate 270' => [JpegOrientation::ROTATE_270],
        ];
    }

    #[DataProvider('keptOrientations')]
    public function testTheWrittenTiffReadsBackItsOrientation(int $orientation): void
    {
        $this->assertSame($orientation, ExifOrientation::fromTiff(ExifOrientation::tiff($orientation)));
    }
}
