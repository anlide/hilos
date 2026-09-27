<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Files\Image\DTO\ImageRenderedSignalData;
use Hilos\Files\Image\DTO\ImageRenderSignalData;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsTmpDirectory;
use Hilos\Hilos;
use Hilos\Socket\Http\DTO\HttpRequestDTO;

/**
 * Draws one queued image copy per tick in its own worker and hands the temporary file to the library.
 * It neither writes the registry nor answers HTTP: the waiting requests travel back with the result.
 */
class ImagesAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_IMAGES;
    public const array AGENT_SIGNALS = [HilosSignalConstants::HILOS_IMAGE_RENDER => ImageRenderSignalData::class];
    public const int MAX_PIXELS = 40_000_000;
    public const string MEMORY_LIMIT = '512M';

    private const int REMEMBERED_KEYS = 1024;
    private const string QUEUE_RENDER = 'render';
    private const string QUEUE_REQUESTS = 'requests';
    private const string UNKNOWN_SIGNATURE = '00000000';

    private ImageEngineInterface $engine;
    private string|false $previousMemoryLimit = false;

    /** @var array<string, array{render: ImageRenderSignalData, requests: list<HttpRequestDTO>}> Coalesced render jobs, in arrival order */
    private array $queue = [];

    /** @var array<string, true> Picture refusals only; a temporary write failure is never remembered */
    private array $failed = [];

    /**
     * A hint that a copy was handed over, never the truth about storage. A retry from the library
     * clears the hint after it finds no live copy, including after a lost result or a sweep.
     *
     * @var array<string, true>
     */
    private array $handedOver = [];

    /**
     * The startup validator asks the same factory before an instance of the agent exists.
     *
     * @return ImageEngineInterface Project-replaceable rendering engine
     */
    public static function createEngine(): ImageEngineInterface
    {
        return new GdImageEngine();
    }

    /** Creates the engine and reserves the process memory budget until this agent stops. */
    public function onStart(): void
    {
        $this->engine = static::createEngine();
        $this->previousMemoryLimit = ini_set('memory_limit', self::MEMORY_LIMIT);
        if ($this->previousMemoryLimit === false) {
            $this->logAgentWarning('Cannot set image rendering memory_limit to ' . self::MEMORY_LIMIT);
        }
    }

    /**
     * Queues requests or answers from the bounded memories; rendering is left to the next tick.
     *
     * @param AgentSignalData $data Wrapped render request
     * @param string $sender Source agent identity
     * @param string $name Signal name
     * @throws AgentUnknownSignalException When this agent owns no signal with that name
     * @throws InvalidAgentSignalPayloadException When the signal carries a different payload
     * @throws InvalidArgumentException When the variant declaration or outbound signal name is invalid
     * @throws InvalidFormatException When a result cannot carry the request's identity
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_IMAGE_RENDER:
                if (!$data->data instanceof ImageRenderSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, ImageRenderSignalData::class, $data->data);
                }
                $this->enqueue($data->data);
                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Renders one whole job: this monopolistic worker may spend its entire tick on the image.
     *
     * @throws InvalidArgumentException When a variant declaration or outbound signal name is invalid
     * @throws InvalidFormatException When the engine's result cannot be carried by the result DTO
     * @throws LogicException When the files door or queued variant is missing
     */
    public function onTick(): void
    {
        $key = array_key_first($this->queue);
        if ($key === null) {
            return;
        }
        $job = $this->queue[$key];
        unset($this->queue[$key]);
        $render = $job[self::QUEUE_RENDER];
        $requests = $job[self::QUEUE_REQUESTS];
        $variant = ImageVariant::named($render->variant) ?? throw new LogicException('Queued image variant is no longer declared');
        $signature = $variant->signature();
        try {
            $bytes = (Hilos::$files ?? throw new LogicException('The files door is not created'))->storage->read($render->storedName);
        } catch (FsException) {
            $this->sendToAgent(HilosSignalConstants::HILOS_IMAGE_RENDERED, ImageRenderedSignalData::missing($render, $signature, $requests));
            return;
        }

        $tmpIndex = null;
        try {
            $probe = $this->engine->probe($bytes);
            if ($probe === null) {
                throw new ImageRenderException('not a picture the engine reads');
            }
            if ($probe->width * $probe->height > self::MAX_PIXELS) {
                throw new ImageRenderException("{$probe->width}x{$probe->height} is above the " . self::MAX_PIXELS . '-pixel ceiling');
            }
            $orientation = $probe->mimeType === ImageFormat::JPEG->value ? JpegOrientation::read($bytes) : JpegOrientation::NORMAL;
            $tmpIndex = $this->tmp()->create();
            $size = $this->engine->render($bytes, $probe, $orientation, $variant, $this->tmp()[$tmpIndex]->getPath());
        } catch (ImageRenderException $e) {
            $this->discardTmp($tmpIndex);
            $this->logAgentWarning("Cannot render variant {$render->variant} of file {$render->fileId}: " . $e->getMessage());
            $this->remember($this->failed, $key);
            $this->sendToAgent(HilosSignalConstants::HILOS_IMAGE_RENDERED, ImageRenderedSignalData::failed($render, $signature, $requests));
            return;
        } catch (FsException $e) {
            $this->discardTmp($tmpIndex);
            $this->logAgentError("Cannot write variant {$render->variant} of file {$render->fileId}: " . $e->getMessage());
            $this->sendToAgent(HilosSignalConstants::HILOS_IMAGE_RENDERED, ImageRenderedSignalData::failed($render, $signature, $requests));
            return;
        }

        $this->sendToAgent(HilosSignalConstants::HILOS_IMAGE_RENDERED,
            ImageRenderedSignalData::rendered($render, $signature, $requests, $tmpIndex, $variant->format->value, $size));
        $this->remember($this->handedOver, $key);
    }

    /** Restores the memory budget for the next agent of this worker; abandoned requests end at the client's timeout. */
    public function onStop(): void
    {
        $this->queue = [];
        $this->failed = [];
        $this->handedOver = [];
        if ($this->previousMemoryLimit !== false) {
            ini_set('memory_limit', $this->previousMemoryLimit);
            $this->previousMemoryLimit = false;
        }
    }

    /**
     * @param ImageRenderSignalData $render Incoming request
     * @throws InvalidArgumentException When the variant declaration or outbound signal name is invalid
     * @throws InvalidFormatException When a result field is malformed
     */
    private function enqueue(ImageRenderSignalData $render): void
    {
        $variant = ImageVariant::named($render->variant);
        if ($variant === null) {
            $this->logAgentError("Image variant {$render->variant} is not declared");
            $this->sendToAgent(HilosSignalConstants::HILOS_IMAGE_RENDERED,
                ImageRenderedSignalData::failed($render, self::UNKNOWN_SIGNATURE, [$render->request]));
            return;
        }
        $signature = $variant->signature();
        $key = "{$render->fileId}|{$render->variant}|{$signature}";
        if (isset($this->failed[$key])) {
            $this->sendToAgent(HilosSignalConstants::HILOS_IMAGE_RENDERED,
                ImageRenderedSignalData::failed($render, $signature, [$render->request]));
            return;
        }
        if (!$render->retry && isset($this->handedOver[$key])) {
            $this->sendToAgent(HilosSignalConstants::HILOS_IMAGE_RENDERED,
                ImageRenderedSignalData::ready($render, $signature, [$render->request]));
            return;
        }
        if ($render->retry) {
            unset($this->handedOver[$key]);
        }
        $this->queue[$key] ??= [self::QUEUE_RENDER => $render, self::QUEUE_REQUESTS => []];
        $this->queue[$key][self::QUEUE_REQUESTS][] = $render->request;
    }

    /**
     * @param array<string, true> $memory Refusals or handed-over hints
     * @param string $key Render identity to remember until restart or eviction
     */
    private function remember(array &$memory, string $key): void
    {
        $memory[$key] = true;
        if (count($memory) > self::REMEMBERED_KEYS) {
            unset($memory[array_key_first($memory)]);
        }
    }

    /** @param ?string $tmpIndex Temporary file left by an unsuccessful attempt */
    private function discardTmp(?string $tmpIndex): void
    {
        if ($tmpIndex === null) {
            return;
        }
        try {
            $this->tmp()[$tmpIndex]->unlink();
        } catch (FsException $e) {
            $this->logAgentError("Cannot remove image temporary file {$tmpIndex}: " . $e->getMessage());
        }
    }

    /**
     * @return FsTmpDirectory The temporary directory shared with the files library
     * @throws DirectoryNotFoundException When the project configures no temporary directory
     */
    private function tmp(): FsTmpDirectory
    {
        return (Hilos::$fs ?? throw new DirectoryNotFoundException('FS context is not configured'))->getTmp();
    }
}
