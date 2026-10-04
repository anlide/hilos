<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

use Hilos\Core\Exception\LogicException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Fs\FsTmpDirectory;
use Hilos\Fs\FsTmpFile;

/** The one entry point for content-detected picture metadata removal. */
final class ImageMetadataStripper
{
    private const int MAGIC_HEADER_BYTES = 12;

    /**
     * The source is never changed here; the caller atomically replaces it only after STRIPPED.
     *
     * @param string $sourcePath Uploaded file on local disk
     * @param FsTmpDirectory $tmp Temporary directory shared with the source
     * @return ImageMetadataStripResult Cleaned file, unchanged file, or malformed input
     * @throws FsException When reading, creating, writing, or cleaning a temporary file fails
     * @throws LogicException When the output digest has already been finalized
     */
    public static function strip(string $sourcePath, FsTmpDirectory $tmp): ImageMetadataStripResult
    {
        $header = FsPath::readWith($sourcePath, static function ($input): string {
            $bytes = fread($input, self::MAGIC_HEADER_BYTES);
            if ($bytes === false) {
                throw new FileReadException('Cannot read picture header');
            }

            return $bytes;
        });
        $format = match (true) {
            str_starts_with($header, "\xff\xd8\xff") => 'jpeg',
            str_starts_with($header, "\x89PNG\r\n\x1a\n") => 'png',
            strlen($header) >= self::MAGIC_HEADER_BYTES && str_starts_with($header, 'RIFF')
                && substr($header, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
        if ($format === null) {
            return ImageMetadataStripResult::notAnImage();
        }
        $index = $tmp->create();
        $file = $tmp[$index];
        $sink = new ImageMetadataSink($file);
        try {
            $changed = FsPath::readWith($sourcePath, static function ($input) use ($format, $sink): bool {
                return match ($format) {
                    'jpeg' => JpegMetadata::strip($input, $sink),
                    'png' => PngMetadata::strip($input, $sink),
                    'webp' => WebpMetadata::strip($input, $sink),
                };
            });
            if (!$changed) {
                $file->unlink();
                return ImageMetadataStripResult::nothingToStrip();
            }

            return ImageMetadataStripResult::stripped($index, $sink->finish());
        } catch (ImageMetadataException $malformed) {
            $file->unlink();

            return ImageMetadataStripResult::malformed($malformed->getMessage());
        } catch (FsException $failure) {
            self::discardAfterFailure($file);
            throw $failure;
        }
    }

    /**
     * @param FsTmpFile $file Incomplete output
     */
    private static function discardAfterFailure(FsTmpFile $file): void
    {
        try {
            $file->unlink();
        } catch (FsException) {
            // The original storage failure remains the caller-facing refusal.
        }
    }
}
