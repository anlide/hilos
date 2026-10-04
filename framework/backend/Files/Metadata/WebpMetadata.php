<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

use Hilos\Core\Exception\LogicException;
use Hilos\Files\Image\JpegOrientation;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;

/** Rewrites extended WebP RIFF sizes and metadata flags while retaining image chunks. */
final class WebpMetadata
{
    private const string EXIF_PREFIX = "Exif\0\0";
    private const int XMP_FLAG = 0x04;
    private const int EXIF_FLAG = 0x08;
    private const int VP8X_BYTES = 10;
    private const int FOURCC_BYTES = 4;
    private const int RIFF_SIZE_FIELD_BYTES = 4;
    private const int RIFF_SIZE_BASE_BYTES = 4;
    private const int RIFF_PREFIX_BYTES = 8;
    private const int RIFF_HEADER_BYTES = 12;
    private const int CHUNK_HEADER_BYTES = 8;
    private const array RETAINED = ['VP8X', 'ICCP', 'ANIM', 'ANMF', 'ALPH', 'VP8 ', 'VP8L'];

    /**
     * @param resource $input Open WebP descriptor
     * @param ImageMetadataSink $output Bounded output writer
     * @return bool Whether a metadata chunk, flag, or trailing byte changed
     * @throws ImageMetadataException When the extended RIFF container is malformed
     * @throws FileReadException When reading fails
     * @throws FileWriteException When writing fails
     * @throws LogicException When the output digest has already been finalized
     */
    public static function strip(mixed $input, ImageMetadataSink $output): bool
    {
        $reader = new ImageMetadataReader($input);
        if ($reader->read(self::FOURCC_BYTES) !== 'RIFF') {
            throw new ImageMetadataException('Missing WebP RIFF header');
        }
        $riffSize = unpack('Vvalue', $reader->read(self::RIFF_SIZE_FIELD_BYTES))['value'];
        if ($reader->read(self::FOURCC_BYTES) !== 'WEBP' || $riffSize < self::RIFF_SIZE_BASE_BYTES
            || $riffSize > $reader->size() - self::RIFF_PREFIX_BYTES) {
            throw new ImageMetadataException('WebP RIFF size exceeds the file');
        }
        $riffEnd = $riffSize + self::RIFF_PREFIX_BYTES;
        if ($riffEnd - $reader->tell() < self::CHUNK_HEADER_BYTES) {
            throw new ImageMetadataException('WebP has no image chunk');
        }
        if ($reader->read(self::FOURCC_BYTES) !== 'VP8X') {
            return false;
        }
        $reader->seek(self::RIFF_HEADER_BYTES);
        $newSize = self::RIFF_SIZE_BASE_BYTES;
        $changed = $riffEnd < $reader->size();
        $hasExif = false;
        $hasImageData = false;
        $vp8xFlags = null;
        while ($reader->tell() < $riffEnd) {
            [$type, $length, $padded] = self::chunkHeader($reader, $riffEnd);
            if ($type === 'VP8X') {
                if ($length !== self::VP8X_BYTES || $vp8xFlags !== null) {
                    throw new ImageMetadataException('Invalid WebP VP8X chunk');
                }
                $vp8xFlags = ord($reader->read(1));
                $reader->skip($padded - 1);
                $newSize += self::CHUNK_HEADER_BYTES + $padded;
                continue;
            }
            if ($type === 'VP8 ' || $type === 'VP8L' || $type === 'ANMF') {
                $hasImageData = true;
            }
            if ($type === 'EXIF') {
                $data = $reader->read($length);
                $reader->skip($padded - $length);
                $tiff = str_starts_with($data, self::EXIF_PREFIX)
                    ? substr($data, strlen(self::EXIF_PREFIX)) : $data;
                $orientation = ExifOrientation::fromTiff($tiff);
                if ($orientation !== JpegOrientation::NORMAL) {
                    $replacement = ExifOrientation::tiff($orientation);
                    $hasExif = true;
                    $newSize += self::CHUNK_HEADER_BYTES + strlen($replacement);
                    $changed = $changed || $data !== $replacement;
                } else {
                    $changed = true;
                }
                continue;
            }
            $reader->skip($padded);
            if (in_array($type, self::RETAINED, true)) {
                $newSize += self::CHUNK_HEADER_BYTES + $padded;
            } else {
                $changed = true;
            }
        }
        if (!$hasImageData || $vp8xFlags === null) {
            throw new ImageMetadataException('WebP has no extended image data');
        }
        $newFlags = ($vp8xFlags & ~self::XMP_FLAG & ~self::EXIF_FLAG) | ($hasExif ? self::EXIF_FLAG : 0);
        $changed = $changed || $newFlags !== $vp8xFlags || $newSize !== $riffSize;
        $output->write('RIFF' . pack('V', $newSize) . 'WEBP');
        $reader->seek(self::RIFF_HEADER_BYTES);
        while ($reader->tell() < $riffEnd) {
            [$type, $length, $padded] = self::chunkHeader($reader, $riffEnd);
            if ($type === 'VP8X') {
                $flags = $reader->read(1);
                $output->write($type . pack('V', $length) . chr($newFlags));
                $reader->copy($padded - strlen($flags), $output);
                continue;
            }
            if ($type === 'EXIF') {
                $data = $reader->read($length);
                $reader->skip($padded - $length);
                $tiff = str_starts_with($data, self::EXIF_PREFIX)
                    ? substr($data, strlen(self::EXIF_PREFIX)) : $data;
                $orientation = ExifOrientation::fromTiff($tiff);
                if ($orientation !== JpegOrientation::NORMAL) {
                    $replacement = ExifOrientation::tiff($orientation);
                    $output->write($type . pack('V', strlen($replacement)) . $replacement);
                }
                continue;
            }
            if (in_array($type, self::RETAINED, true)) {
                $output->write($type . pack('V', $length));
                $reader->copy($padded, $output);
            } else {
                $reader->skip($padded);
            }
        }

        return $changed;
    }

    /**
     * @param ImageMetadataReader $reader Input at the next chunk header
     * @param int $riffEnd Exclusive end of the declared RIFF body
     * @return array{string, int, int} FourCC, data size, size including pad
     * @throws ImageMetadataException When the chunk is truncated
     * @throws FileReadException When reading fails
     */
    private static function chunkHeader(ImageMetadataReader $reader, int $riffEnd): array
    {
        if ($riffEnd - $reader->tell() < self::CHUNK_HEADER_BYTES) {
            throw new ImageMetadataException('Truncated WebP chunk header');
        }
        $type = $reader->read(self::FOURCC_BYTES);
        $length = unpack('Vvalue', $reader->read(self::RIFF_SIZE_FIELD_BYTES))['value'];
        $padded = $length + ($length % 2);
        if ($padded > $riffEnd - $reader->tell()) {
            throw new ImageMetadataException('WebP chunk length exceeds RIFF size');
        }

        return [$type, $length, $padded];
    }
}
