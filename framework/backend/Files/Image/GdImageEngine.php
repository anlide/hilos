<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

use GdImage;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use ValueError;

/** GD renderer: one still frame, EXIF-oriented, with no source metadata in the encoded copy. */
final class GdImageEngine implements ImageEngineInterface
{
    private const int WEBP_QUALITY = 82;
    private const int JPEG_QUALITY = 85;
    private const int PNG_COMPRESSION = 6;
    private const int COLOR_MAX = 255;
    private const int ALPHA_TRANSPARENT = 127;
    private const int HALF_TURN_DEGREES = 180;
    private const int QUARTER_TURN_DEGREES = 90;

    /**
     * @param list<ImageFormat> $outputs Formats declared by the project
     * @return ?string Missing GD capability, or null when all outputs are available
     */
    public function unusableReason(array $outputs): ?string
    {
        if (!extension_loaded('gd')) {
            return 'PHP extension gd is not loaded';
        }
        foreach ($outputs as $output) {
            $bit = match ($output) {
                ImageFormat::WEBP => IMG_WEBP,
                ImageFormat::JPEG => IMG_JPG,
                ImageFormat::PNG => IMG_PNG,
            };
            if ((imagetypes() & $bit) === 0) {
                return 'gd is built without ' . strtolower($output->name);
            }
        }

        return null;
    }

    /**
     * Reads dimensions before allocating pixels, including a header over the agent's ceiling.
     *
     * @param string $bytes Encoded source
     * @return ?ImageProbe Supported header, or null for unreadable or unsupported bytes
     */
    public function probe(string $bytes): ?ImageProbe
    {
        try {
            $header = self::withImageWarnings(static fn() => getimagesizefromstring($bytes));
        } catch (ImageRenderException) {
            return null;
        }
        if ($header === false || !ImageFormat::readsSource($header['mime']) || $header[0] <= 0 || $header[1] <= 0) {
            return null;
        }

        return new ImageProbe($header['mime'], $header[0], $header[1]);
    }

    /**
     * @param string $bytes Encoded original
     * @param ImageProbe $probe Header already checked against the agent's pixel ceiling
     * @param int $orientation EXIF orientation in 1..8
     * @param ImageVariant $variant Frame and encoding of the copy
     * @param string $targetPath Temporary file to receive the encoded copy
     * @return int Encoded byte count
     * @throws ImageRenderException When the picture cannot be decoded or transformed; the agent remembers this refusal
     * @throws FsException When the copy cannot be written or measured; the agent may try again
     */
    public function render(string $bytes, ImageProbe $probe, int $orientation, ImageVariant $variant, string $targetPath): int
    {
        $copy = self::withImageWarnings(function () use ($bytes, $probe, $orientation, $variant): GdImage {
            $source = imagecreatefromstring($bytes);
            if ($source === false) {
                throw new ImageRenderException('GD cannot decode the image');
            }
            if (imagesx($source) !== $probe->width || imagesy($source) !== $probe->height) {
                throw new ImageRenderException('Decoded dimensions differ from the image header');
            }
            $source = self::orient($source, $orientation);
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $cropWidth = $sourceWidth;
            $cropHeight = $sourceHeight;
            if ($variant->fit === ImageFit::COVER) {
                if ($sourceWidth * $variant->height > $sourceHeight * $variant->width) {
                    $cropWidth = max(1, (int)round($sourceHeight * $variant->width / $variant->height));
                } else {
                    $cropHeight = max(1, (int)round($sourceWidth * $variant->height / $variant->width));
                }
            }
            $scale = min($variant->width / $cropWidth, $variant->height / $cropHeight, 1);
            $width = max(1, (int)round($cropWidth * $scale));
            $height = max(1, (int)round($cropHeight * $scale));
            $copy = imagecreatetruecolor($width, $height);
            if ($copy === false) {
                throw new ImageRenderException('GD cannot allocate the copy');
            }
            if ($variant->format === ImageFormat::JPEG) {
                imagefill($copy, 0, 0, imagecolorallocate($copy, self::COLOR_MAX, self::COLOR_MAX, self::COLOR_MAX));
            } else {
                imagealphablending($copy, false);
                imagesavealpha($copy, true);
                imagefill($copy, 0, 0, imagecolorallocatealpha($copy, 0, 0, 0, self::ALPHA_TRANSPARENT));
            }
            if (!imagecopyresampled($copy, $source, 0, 0, intdiv($sourceWidth - $cropWidth, 2), intdiv($sourceHeight - $cropHeight, 2),
                $width, $height, $cropWidth, $cropHeight)) {
                throw new ImageRenderException('GD cannot resample the image');
            }
            return $copy;
        });
        self::withWriteWarnings(function () use ($copy, $variant, $targetPath): void {
            $written = match ($variant->format) {
                ImageFormat::WEBP => imagewebp($copy, $targetPath, self::WEBP_QUALITY),
                ImageFormat::JPEG => imagejpeg($copy, $targetPath, self::JPEG_QUALITY),
                ImageFormat::PNG => imagepng($copy, $targetPath, self::PNG_COMPRESSION),
            };
            if (!$written) {
                throw new FileWriteException('Cannot write the image copy: GD could not encode it');
            }
        });
        $size = FsPath::size($targetPath);
        if ($size <= 0) {
            throw new FileWriteException('Cannot write the image copy: GD produced an empty file');
        }

        return $size;
    }

    /**
     * @param GdImage $image Decoded source
     * @param int $orientation EXIF orientation
     * @return GdImage Upright source, with width and height exchanged for quarter turns
     * @throws ImageRenderException When GD cannot transform the image
     */
    private static function orient(GdImage $image, int $orientation): GdImage
    {
        $flip = match ($orientation) {
            JpegOrientation::MIRROR_HORIZONTAL, JpegOrientation::TRANSPOSE, JpegOrientation::TRANSVERSE => IMG_FLIP_HORIZONTAL,
            JpegOrientation::MIRROR_VERTICAL => IMG_FLIP_VERTICAL,
            default => null,
        };
        if ($flip !== null && !imageflip($image, $flip)) {
            throw new ImageRenderException('GD cannot reflect the image');
        }
        $angle = match ($orientation) {
            JpegOrientation::ROTATE_180 => self::HALF_TURN_DEGREES,
            JpegOrientation::TRANSPOSE, JpegOrientation::ROTATE_270 => self::QUARTER_TURN_DEGREES,
            JpegOrientation::ROTATE_90, JpegOrientation::TRANSVERSE => -self::QUARTER_TURN_DEGREES,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, imagecolorallocatealpha($image, 0, 0, 0, self::ALPHA_TRANSPARENT));
        if ($rotated === false) {
            throw new ImageRenderException('GD cannot rotate the image');
        }
        imagesavealpha($rotated, true);

        return $rotated;
    }

    /**
     * Keeps malformed images out of the worker's fatal warning handler and always restores it.
     *
     * @template T
     * @param callable(): T $operation GD or image-header operation
     * @return T Operation's result
     * @throws ImageRenderException When the operation warns or rejects its input
     */
    private static function withImageWarnings(callable $operation): mixed
    {
        $previous = null;
        $previous = set_error_handler(
            static function (int $severity, string $message, string $file, int $line) use (&$previous): bool {
                if ($severity === E_WARNING || $severity === E_NOTICE || $severity === E_USER_WARNING) {
                    throw new ImageRenderException($message);
                }

                return $previous !== null ? $previous($severity, $message, $file, $line) : false;
            },
        );
        try {
            return $operation();
        } catch (ValueError $e) {
            throw new ImageRenderException($e->getMessage(), 0, $e);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A write refusal belongs to this attempt, not to the picture, and must not enter the agent's failed-key memory.
     *
     * @param callable(): void $operation Encoder writing the temporary copy
     * @throws FileWriteException When the encoder warns or refuses the write
     */
    private static function withWriteWarnings(callable $operation): void
    {
        $previous = null;
        $previous = set_error_handler(
            static function (int $severity, string $message, string $file, int $line) use (&$previous): bool {
                if ($severity === E_WARNING || $severity === E_NOTICE || $severity === E_USER_WARNING) {
                    throw new FileWriteException('Cannot write the image copy: ' . $message);
                }

                return $previous !== null ? $previous($severity, $message, $file, $line) : false;
            },
        );
        try {
            $operation();
        } catch (ValueError $e) {
            throw new FileWriteException('Cannot write the image copy: ' . $e->getMessage(), 0, $e);
        } finally {
            restore_error_handler();
        }
    }
}
