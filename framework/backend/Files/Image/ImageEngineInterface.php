<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

use Hilos\Fs\FsException;

/** The image agent's replaceable decoding and rendering engine. */
interface ImageEngineInterface
{
    /**
     * @param list<ImageFormat> $outputs Formats the project declares
     * @return ?string Missing capability, or null when the engine can produce every format
     */
    public function unusableReason(array $outputs): ?string;

    /**
     * @param string $bytes Encoded original
     * @return ?ImageProbe Supported source header, or null when the bytes cannot be read as an image
     */
    public function probe(string $bytes): ?ImageProbe;

    /**
     * @param string $bytes Encoded original
     * @param ImageProbe $probe Header whose dimensions passed the agent's pixel ceiling
     * @param int $orientation EXIF orientation, 1..8
     * @param ImageVariant $variant Copy's declared frame, fit and format
     * @param string $targetPath Temporary file to encode into
     * @return int Written byte count
     * @throws ImageRenderException When the picture cannot be decoded or transformed; the agent remembers this refusal
     * @throws FsException When the copy cannot be written or measured; the agent may try again
     */
    public function render(string $bytes, ImageProbe $probe, int $orientation, ImageVariant $variant, string $targetPath): int;
}
