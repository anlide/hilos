<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\LogicException;
use Hilos\Fs\FsException;
use Hilos\Hilos;

/**
 * The shared pixel ceiling, JPEG orientation and render for one image copy.
 *
 * A caller in its own monopolistic process draws a copy of a file it has not
 * published, then deletes that copy itself.
 */
final class ImageCopy
{
    public const int MAX_PIXELS = 40_000_000;

    /**
     * Engine of the images worker the project registered.
     *
     * The images agent does not call this: it already holds the engine from its start.
     * A caller that draws a copy of a file it has not published asks here, so a
     * subclassed worker keeps its engine.
     *
     * @return ImageEngineInterface That worker's engine
     * @throws LogicException When images is not declared with an images worker
     */
    public static function engine(): ImageEngineInterface
    {
        $class = Hilos::appClass()::AGENTS[HilosAgentType::HILOS_IMAGES][AgentRegistryKey::WORKER] ?? null;
        if (!is_string($class) || !is_a($class, ImagesAgent::class, true)) {
            throw new LogicException('IMAGES is not declared with an images worker');
        }

        return $class::createEngine();
    }

    /**
     * @param ImageEngineInterface $engine Engine that probes and renders
     * @param string $bytes Encoded original
     * @param ImageVariant $variant Copy's declared frame, fit and format
     * @param string $targetPath File that receives the encoded copy
     * @return int Written byte count
     * @throws ImageRenderException When the picture cannot be decoded or transformed
     * @throws FsException When the copy cannot be written or measured
     */
    public static function draw(ImageEngineInterface $engine, string $bytes, ImageVariant $variant, string $targetPath): int
    {
        $probe = $engine->probe($bytes);
        if ($probe === null) {
            throw new ImageRenderException('not a picture the engine reads');
        }
        if ($probe->width * $probe->height > self::MAX_PIXELS) {
            throw new ImageRenderException("{$probe->width}x{$probe->height} is above the " . self::MAX_PIXELS . '-pixel ceiling');
        }
        $orientation = $probe->mimeType === ImageFormat::JPEG->value ? JpegOrientation::read($bytes) : JpegOrientation::NORMAL;

        return $engine->render($bytes, $probe, $orientation, $variant, $targetPath);
    }
}
