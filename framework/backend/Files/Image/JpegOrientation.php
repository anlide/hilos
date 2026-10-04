<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

use Hilos\Files\Metadata\ExifOrientation;

/** Reads only the EXIF orientation in IFD0; no ext-exif dependency or full metadata decoding. */
final class JpegOrientation
{
    public const int NORMAL = 1;
    public const int MIRROR_HORIZONTAL = 2;
    public const int ROTATE_180 = 3;
    public const int MIRROR_VERTICAL = 4;
    public const int TRANSPOSE = 5;
    public const int ROTATE_90 = 6;
    public const int TRANSVERSE = 7;
    public const int ROTATE_270 = 8;

    private const string SOI = "\xff\xd8";
    private const string MARKER_PREFIX = "\xff";
    private const string EXIF_HEADER = "Exif\0\0";
    private const int MARKER_SOS = 0xda;
    private const int MARKER_EOI = 0xd9;
    private const int MARKER_APP1 = 0xe1;
    private const int MARKER_TEM = 0x01;
    private const int MARKER_RESTART_FIRST = 0xd0;
    private const int MARKER_RESTART_LAST = 0xd7;

    /**
     * @param string $bytes Encoded JPEG
     * @return int Orientation in 1..8, NORMAL for absent or malformed metadata
     */
    public static function read(string $bytes): int
    {
        if (!str_starts_with($bytes, self::SOI)) {
            return self::NORMAL;
        }
        $position = strlen(self::SOI);
        $length = strlen($bytes);
        while ($position < $length) {
            if ($bytes[$position] !== self::MARKER_PREFIX) {
                return self::NORMAL;
            }
            while ($position < $length && $bytes[$position] === self::MARKER_PREFIX) {
                $position++;
            }
            if ($position >= $length) {
                return self::NORMAL;
            }
            $marker = ord($bytes[$position++]);
            if ($marker === self::MARKER_SOS || $marker === self::MARKER_EOI) {
                return self::NORMAL;
            }
            if ($marker === self::MARKER_TEM || ($marker >= self::MARKER_RESTART_FIRST && $marker <= self::MARKER_RESTART_LAST)) {
                continue;
            }
            if ($length - $position < 2) {
                return self::NORMAL;
            }
            $segmentLength = unpack('nvalue', substr($bytes, $position, 2))['value'];
            if ($segmentLength === null || $segmentLength < 2 || $segmentLength > $length - $position) {
                return self::NORMAL;
            }
            $segment = substr($bytes, $position + 2, $segmentLength - 2);
            if ($marker === self::MARKER_APP1 && str_starts_with($segment, self::EXIF_HEADER)) {
                return ExifOrientation::fromTiff(substr($segment, strlen(self::EXIF_HEADER)));
            }
            $position += $segmentLength;
        }

        return self::NORMAL;
    }

}
