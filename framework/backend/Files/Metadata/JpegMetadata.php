<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

use Hilos\Core\Exception\LogicException;
use Hilos\Files\Image\JpegOrientation;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;

/** Removes metadata markers from every JPEG scan without decoding pixels. */
final class JpegMetadata
{
    private const string SOI = "\xff\xd8";
    private const string EXIF = "Exif\0\0";
    private const int EOI = 0xd9;
    private const int SOS = 0xda;
    private const int APP_FIRST = 0xe0;
    private const int APP_LAST = 0xef;
    private const int APP_EXIF = 0xe1;
    private const int COMMENT = 0xfe;
    private const int RESTART_FIRST = 0xd0;
    private const int RESTART_LAST = 0xd7;
    private const int SCAN_BYTES = 65536;

    /**
     * @param resource $input Open JPEG descriptor
     * @param ImageMetadataSink $output Bounded output writer
     * @return bool Whether at least one byte was removed or rewritten
     * @throws ImageMetadataException When the header container is malformed
     * @throws FileReadException When reading fails
     * @throws FileWriteException When writing fails
     * @throws LogicException When the output digest has already been finalized
     */
    public static function strip(mixed $input, ImageMetadataSink $output): bool
    {
        $reader = new ImageMetadataReader($input);
        if ($reader->read(2) !== self::SOI) {
            throw new ImageMetadataException('Missing JPEG start marker');
        }
        $output->write(self::SOI);
        $changed = false;
        $hasScan = false;
        while ($reader->tell() < $reader->size()) {
            $markerBytes = self::marker($reader);
            $marker = ord(substr($markerBytes, -1));
            if ($marker === self::EOI) {
                if (!$hasScan) {
                    throw new ImageMetadataException('JPEG has no image scan');
                }
                $output->write($markerBytes);

                return $changed || $reader->tell() < $reader->size();
            }
            if ($marker === 0x01 || ($marker >= self::RESTART_FIRST && $marker <= self::RESTART_LAST)) {
                $output->write($markerBytes);
                continue;
            }
            $lengthBytes = $reader->read(2);
            $length = unpack('nvalue', $lengthBytes)['value'];
            if ($length < 2 || $length - 2 > $reader->size() - $reader->tell()) {
                throw new ImageMetadataException('JPEG marker length exceeds the file');
            }
            $dataLength = $length - 2;
            if ($marker === self::SOS) {
                $hasScan = true;
                $output->write($markerBytes . $lengthBytes);
                $reader->copy($dataLength, $output);
                if (!self::copyScan($reader, $output)) {
                    return $changed;
                }
                continue;
            }
            if ($marker === self::APP_EXIF) {
                $data = $reader->read($dataLength);
                if (str_starts_with($data, self::EXIF)) {
                    $orientation = ExifOrientation::fromTiff(substr($data, strlen(self::EXIF)));
                    if ($orientation !== JpegOrientation::NORMAL) {
                        $replacement = self::EXIF . ExifOrientation::tiff($orientation);
                        $output->write($markerBytes . pack('n', strlen($replacement) + 2) . $replacement);
                        $changed = $changed || $data !== $replacement;
                    } else {
                        $changed = true;
                    }
                } else {
                    $changed = true;
                }
                continue;
            }
            if ($marker >= self::APP_FIRST && $marker <= self::APP_LAST) {
                if (self::isRetainedApp($marker, $reader, $dataLength, $output, $markerBytes, $lengthBytes)) {
                    continue;
                }
                $changed = true;
                continue;
            }
            if ($marker === self::COMMENT) {
                $reader->skip($dataLength);
                $changed = true;
                continue;
            }
            $output->write($markerBytes . $lengthBytes);
            $reader->copy($dataLength, $output);
        }

        throw new ImageMetadataException('JPEG ended before its image scan');
    }

    /**
     * @param ImageMetadataReader $reader JPEG input
     * @return string Complete marker, including fill bytes
     * @throws ImageMetadataException When a marker is missing
     * @throws FileReadException When reading fails
     */
    private static function marker(ImageMetadataReader $reader): string
    {
        if ($reader->byte() !== "\xff") {
            throw new ImageMetadataException('Expected JPEG marker');
        }
        $bytes = "\xff";
        do {
            $next = $reader->byte();
            if ($next === null) {
                throw new ImageMetadataException('Truncated JPEG marker');
            }
            $bytes .= $next;
        } while ($next === "\xff");
        if ($next === "\0") {
            throw new ImageMetadataException('Stuffed byte outside JPEG scan');
        }

        return $bytes;
    }

    /**
     * @param int $marker APP marker number
     * @param ImageMetadataReader $reader JPEG input
     * @param int $length Segment data length
     * @param ImageMetadataSink $output Output writer
     * @param string $markerBytes Original marker bytes
     * @param string $lengthBytes Original two-byte length
     * @return bool Whether the APP segment was retained
     * @throws FileReadException When reading fails
     * @throws FileWriteException When writing fails
     * @throws LogicException When the output digest has already been finalized
     */
    private static function isRetainedApp(
        int $marker,
        ImageMetadataReader $reader,
        int $length,
        ImageMetadataSink $output,
        string $markerBytes,
        string $lengthBytes,
    ): bool {
        $prefix = $reader->read(min($length, 12));
        $keep = match ($marker) {
            0xe0 => str_starts_with($prefix, "JFIF\0"),
            0xe2 => str_starts_with($prefix, "ICC_PROFILE\0"),
            0xee => str_starts_with($prefix, 'Adobe'),
            default => false,
        };
        if ($keep) {
            $output->write($markerBytes . $lengthBytes . $prefix);
            $reader->copy($length - strlen($prefix), $output);
        } else {
            $reader->skip($length - strlen($prefix));
        }

        return $keep;
    }

    /**
     * @param ImageMetadataReader $reader JPEG input at entropy data
     * @param ImageMetadataSink $output Output writer
     * @return bool Whether another marker starts; false for a truncated final scan
     * @throws FileReadException When reading fails
     * @throws FileWriteException When writing fails
     * @throws LogicException When the output digest has already been finalized
     */
    private static function copyScan(ImageMetadataReader $reader, ImageMetadataSink $output): bool
    {
        while ($reader->tell() < $reader->size()) {
            $start = $reader->tell();
            $chunk = $reader->read(min(self::SCAN_BYTES, $reader->size() - $start));
            $position = strpos($chunk, "\xff");
            if ($position === false) {
                $output->write($chunk);
                continue;
            }
            $output->write(substr($chunk, 0, $position));
            $reader->seek($start + $position);
            $candidate = '';
            do {
                $next = $reader->byte();
                if ($next === null) {
                    $output->write($candidate);
                    return false;
                }
                $candidate .= $next;
            } while ($next === "\xff");
            $code = ord($next);
            if ($code === 0 || ($code >= self::RESTART_FIRST && $code <= self::RESTART_LAST)) {
                $output->write($candidate);
                continue;
            }
            $reader->seek($start + $position);

            return true;
        }

        return false;
    }
}
