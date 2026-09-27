<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

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
    private const string LITTLE_ENDIAN = 'II';
    private const string BIG_ENDIAN = 'MM';
    private const int MARKER_SOS = 0xda;
    private const int MARKER_EOI = 0xd9;
    private const int MARKER_APP1 = 0xe1;
    private const int MARKER_TEM = 0x01;
    private const int MARKER_RESTART_FIRST = 0xd0;
    private const int MARKER_RESTART_LAST = 0xd7;
    private const int TIFF_MAGIC = 42;
    private const int TIFF_HEADER_BYTES = 8;
    private const int TIFF_IFD_OFFSET_POSITION = 4;
    private const int IFD_ENTRY_BYTES = 12;
    private const int IFD_COUNT_POSITION = 4;
    private const int IFD_VALUE_POSITION = 8;
    private const int ORIENTATION_TAG = 0x0112;
    private const int TIFF_SHORT = 3;
    private const int UINT32_BYTES = 4;

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
            $segmentLength = self::uint16($bytes, $position, false);
            if ($segmentLength === null || $segmentLength < 2 || $segmentLength > $length - $position) {
                return self::NORMAL;
            }
            $segment = substr($bytes, $position + 2, $segmentLength - 2);
            if ($marker === self::MARKER_APP1 && str_starts_with($segment, self::EXIF_HEADER)) {
                return self::readTiff(substr($segment, strlen(self::EXIF_HEADER)));
            }
            $position += $segmentLength;
        }

        return self::NORMAL;
    }

    /**
     * @param string $tiff TIFF bytes beginning with the byte-order marker
     * @return int Orientation, NORMAL when the bounded IFD0 cannot supply one
     */
    private static function readTiff(string $tiff): int
    {
        $order = substr($tiff, 0, 2);
        if (strlen($tiff) < self::TIFF_HEADER_BYTES || !in_array($order, [self::LITTLE_ENDIAN, self::BIG_ENDIAN], true)) {
            return self::NORMAL;
        }
        $little = $order === self::LITTLE_ENDIAN;
        if (self::uint16($tiff, 2, $little) !== self::TIFF_MAGIC) {
            return self::NORMAL;
        }
        $ifd = self::uint32($tiff, self::TIFF_IFD_OFFSET_POSITION, $little);
        if ($ifd === null || $ifd < self::TIFF_HEADER_BYTES) {
            return self::NORMAL;
        }
        $count = self::uint16($tiff, $ifd, $little);
        if ($count === null || $count > intdiv(strlen($tiff) - $ifd - 2, self::IFD_ENTRY_BYTES)) {
            return self::NORMAL;
        }
        for ($index = 0; $index < $count; $index++) {
            $entry = $ifd + 2 + $index * self::IFD_ENTRY_BYTES;
            if (self::uint16($tiff, $entry, $little) !== self::ORIENTATION_TAG) {
                continue;
            }
            if (self::uint16($tiff, $entry + 2, $little) !== self::TIFF_SHORT
                || self::uint32($tiff, $entry + self::IFD_COUNT_POSITION, $little) !== 1) {
                return self::NORMAL;
            }
            $orientation = self::uint16($tiff, $entry + self::IFD_VALUE_POSITION, $little);

            return $orientation !== null && $orientation >= self::NORMAL && $orientation <= self::ROTATE_270
                ? $orientation : self::NORMAL;
        }

        return self::NORMAL;
    }

    /**
     * @param string $bytes Binary field container
     * @param int $offset Offset in bytes
     * @param bool $little Whether the field is little-endian
     * @return ?int Unsigned value, null when the field is truncated
     */
    private static function uint16(string $bytes, int $offset, bool $little): ?int
    {
        if ($offset < 0 || $offset > strlen($bytes) - 2) {
            return null;
        }

        return unpack($little ? 'vvalue' : 'nvalue', substr($bytes, $offset, 2))['value'];
    }

    /**
     * @param string $bytes Binary field container
     * @param int $offset Offset in bytes
     * @param bool $little Whether the field is little-endian
     * @return ?int Unsigned value, null when the field is truncated
     */
    private static function uint32(string $bytes, int $offset, bool $little): ?int
    {
        if ($offset < 0 || $offset > strlen($bytes) - self::UINT32_BYTES) {
            return null;
        }

        return unpack($little ? 'Vvalue' : 'Nvalue', substr($bytes, $offset, self::UINT32_BYTES))['value'];
    }
}
