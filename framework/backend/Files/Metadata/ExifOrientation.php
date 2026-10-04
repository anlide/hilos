<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

use Hilos\Files\Image\JpegOrientation;

/** Reads only IFD0 Orientation and writes a minimal TIFF with that one tag. */
final class ExifOrientation
{
    private const string LITTLE_ENDIAN = 'II';
    private const string BIG_ENDIAN = 'MM';
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
     * @param string $tiff TIFF bytes beginning with the byte-order marker
     * @return int Orientation, NORMAL when bounded IFD0 cannot supply one
     */
    public static function fromTiff(string $tiff): int
    {
        $order = substr($tiff, 0, 2);
        if (strlen($tiff) < self::TIFF_HEADER_BYTES || !in_array($order, [self::LITTLE_ENDIAN, self::BIG_ENDIAN], true)) {
            return JpegOrientation::NORMAL;
        }
        $little = $order === self::LITTLE_ENDIAN;
        if (self::uint16($tiff, 2, $little) !== self::TIFF_MAGIC) {
            return JpegOrientation::NORMAL;
        }
        $ifd = self::uint32($tiff, self::TIFF_IFD_OFFSET_POSITION, $little);
        if ($ifd === null || $ifd < self::TIFF_HEADER_BYTES) {
            return JpegOrientation::NORMAL;
        }
        $count = self::uint16($tiff, $ifd, $little);
        if ($count === null || $count > intdiv(strlen($tiff) - $ifd - 2, self::IFD_ENTRY_BYTES)) {
            return JpegOrientation::NORMAL;
        }
        for ($index = 0; $index < $count; $index++) {
            $entry = $ifd + 2 + $index * self::IFD_ENTRY_BYTES;
            if (self::uint16($tiff, $entry, $little) !== self::ORIENTATION_TAG) {
                continue;
            }
            if (self::uint16($tiff, $entry + 2, $little) !== self::TIFF_SHORT
                || self::uint32($tiff, $entry + self::IFD_COUNT_POSITION, $little) !== 1) {
                return JpegOrientation::NORMAL;
            }
            $orientation = self::uint16($tiff, $entry + self::IFD_VALUE_POSITION, $little);

            return $orientation !== null && $orientation >= JpegOrientation::NORMAL && $orientation <= JpegOrientation::ROTATE_270
                ? $orientation : JpegOrientation::NORMAL;
        }

        return JpegOrientation::NORMAL;
    }

    /**
     * @param int $orientation TIFF Orientation value in 2..8
     * @return string Minimal little-endian TIFF with one IFD0 Orientation tag
     */
    public static function tiff(int $orientation): string
    {
        return self::LITTLE_ENDIAN . pack('vVvvvVvvV', self::TIFF_MAGIC, self::TIFF_HEADER_BYTES, 1,
            self::ORIENTATION_TAG, self::TIFF_SHORT, 1, $orientation, 0, 0);
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
