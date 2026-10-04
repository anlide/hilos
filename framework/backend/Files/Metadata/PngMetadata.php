<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

use Hilos\Core\Exception\LogicException;
use Hilos\Files\Image\JpegOrientation;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;

/** Keeps PNG image and rendering chunks while dropping ancillary metadata. */
final class PngMetadata
{
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const int LENGTH_BYTES = 4;
    private const int TYPE_BYTES = 4;
    private const int CRC_BYTES = 4;
    private const array RENDERING_CHUNKS = [
        'tRNS', 'cHRM', 'gAMA', 'iCCP', 'sBIT', 'sRGB', 'cICP', 'mDCV', 'cLLI',
        'bKGD', 'pHYs', 'sPLT', 'hIST', 'acTL', 'fcTL', 'fdAT',
    ];

    /**
     * @param resource $input Open PNG descriptor
     * @param ImageMetadataSink $output Bounded output writer
     * @return bool Whether any chunk or trailing byte was removed or rewritten
     * @throws ImageMetadataException When the container is malformed
     * @throws FileReadException When reading fails
     * @throws FileWriteException When writing fails
     * @throws LogicException When the output digest has already been finalized
     */
    public static function strip(mixed $input, ImageMetadataSink $output): bool
    {
        $reader = new ImageMetadataReader($input);
        if ($reader->read(strlen(self::SIGNATURE)) !== self::SIGNATURE) {
            throw new ImageMetadataException('Missing PNG signature');
        }
        $output->write(self::SIGNATURE);
        $changed = false;
        $hasImageData = false;
        while ($reader->tell() < $reader->size()) {
            $lengthBytes = $reader->read(self::LENGTH_BYTES);
            $length = unpack('Nvalue', $lengthBytes)['value'];
            $type = $reader->read(self::TYPE_BYTES);
            if ($length > $reader->size() - $reader->tell() - self::CRC_BYTES) {
                throw new ImageMetadataException('PNG chunk length exceeds the file');
            }
            if ($type === 'IDAT') {
                $hasImageData = true;
            }
            if ($type === 'eXIf') {
                $data = $reader->read($length);
                $originalCrc = $reader->read(self::CRC_BYTES);
                $orientation = ExifOrientation::fromTiff($data);
                if ($orientation === JpegOrientation::NORMAL) {
                    $changed = true;
                    continue;
                }
                $replacement = ExifOrientation::tiff($orientation);
                $output->write(pack('N', strlen($replacement)) . $type . $replacement);
                $replacementCrc = hex2bin(hash('crc32b', $type . $replacement));
                $output->write($replacementCrc);
                $changed = $changed || $data !== $replacement || $originalCrc !== $replacementCrc;
                continue;
            }
            $critical = ctype_upper($type[0]);
            if (!$critical && !in_array($type, self::RENDERING_CHUNKS, true)) {
                $reader->skip($length + self::CRC_BYTES);
                $changed = true;
                continue;
            }
            $output->write($lengthBytes . $type);
            $reader->copy($length + self::CRC_BYTES, $output);
            if ($type === 'IEND') {
                if (!$hasImageData) {
                    throw new ImageMetadataException('PNG has no image data');
                }

                return $changed || $reader->tell() < $reader->size();
            }
        }

        throw new ImageMetadataException('PNG ended before IEND');
    }
}
