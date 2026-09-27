<?php

declare(strict_types=1);

namespace Hilos\Files\Image\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Files\Image\ImageRenderOutcome;
use Hilos\Files\Image\ImageVariant;
use Hilos\Socket\Http\DTO\HttpRequestDTO;

/** Images agent → files library: a temporary copy or a refusal, with every request waiting for it. */
final class ImageRenderedSignalData extends BaseDTO implements SignalDataInterface
{
    public const string outcome = 'outcome';
    public const string fileId = 'fileId';
    public const string variant = 'variant';
    public const string signature = 'signature';
    public const string requests = 'requests';
    public const string tmpIndex = 'tmpIndex';
    public const string mimeType = 'mimeType';
    public const string size = 'size';

    /**
     * @param ImageRenderOutcome $outcome Result of rendering or consulting the agent's memory
     * @param int $fileId Original registry row
     * @param string $variant Copy name
     * @param string $signature Fingerprint of the rendering settings
     * @param list<HttpRequestDTO> $requests Every request waiting for this copy
     * @param ?string $tmpIndex Temporary copy; present only for rendered
     * @param ?string $mimeType Copy's type; present only for rendered
     * @param ?int $size Copy's byte count; present only for rendered
     * @throws InvalidFormatException When the identity, requests or outcome's file fields are malformed
     */
    public function __construct(
        public readonly ImageRenderOutcome $outcome,
        public readonly int $fileId,
        public readonly string $variant,
        public readonly string $signature,
        public readonly array $requests,
        public readonly ?string $tmpIndex = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $size = null,
    ) {
        if ($fileId <= 0 || preg_match(ImageVariant::NAME_PATTERN, $variant) !== 1
            || preg_match(ImageVariant::SIGNATURE_PATTERN, $signature) !== 1) {
            throw new InvalidFormatException('Image result needs a positive file id, valid variant and signature');
        }
        if ($requests === [] || !array_is_list($requests)) {
            throw new InvalidFormatException('Image result needs a nonempty list of waiting requests');
        }
        foreach ($requests as $request) {
            if (!$request instanceof HttpRequestDTO) {
                throw new InvalidFormatException('Image result holds a value that is not an HTTP request');
            }
        }
        if ($outcome === ImageRenderOutcome::RENDERED) {
            if ($tmpIndex === null || $tmpIndex === '' || $mimeType === null || $mimeType === '' || $size === null || $size <= 0) {
                throw new InvalidFormatException('Rendered image needs a temporary file, MIME type and positive size');
            }
        } elseif ($tmpIndex !== null || $mimeType !== null || $size !== null) {
            throw new InvalidFormatException('Only a rendered image may carry file fields');
        }
    }

    /**
     * @param ImageRenderSignalData $render First request for the copy
     * @param string $signature Rendering settings fingerprint
     * @param list<HttpRequestDTO> $requests Waiting requests
     * @param string $tmpIndex Encoded temporary file
     * @param string $mimeType Encoded type
     * @param int $size Encoded byte count
     * @return self Completed rendering for the library to store
     * @throws InvalidFormatException When a result field is malformed
     */
    public static function rendered(
        ImageRenderSignalData $render,
        string $signature,
        array $requests,
        string $tmpIndex,
        string $mimeType,
        int $size,
    ): self {
        return new self(ImageRenderOutcome::RENDERED, $render->fileId, $render->variant, $signature, $requests, $tmpIndex, $mimeType, $size);
    }

    /**
     * @param ImageRenderSignalData $render First request for the copy
     * @param string $signature Rendering settings fingerprint
     * @param list<HttpRequestDTO> $requests Waiting requests
     * @return self Hint that a copy was handed over; the library must check its storage
     * @throws InvalidFormatException When a result field is malformed
     */
    public static function ready(ImageRenderSignalData $render, string $signature, array $requests): self
    {
        return new self(ImageRenderOutcome::READY, $render->fileId, $render->variant, $signature, $requests);
    }

    /**
     * @param ImageRenderSignalData $render First request for the copy
     * @param string $signature Rendering settings fingerprint
     * @param list<HttpRequestDTO> $requests Waiting requests
     * @return self Rendering refusal, for the library to serve the original
     * @throws InvalidFormatException When a result field is malformed
     */
    public static function failed(ImageRenderSignalData $render, string $signature, array $requests): self
    {
        return new self(ImageRenderOutcome::FAILED, $render->fileId, $render->variant, $signature, $requests);
    }

    /**
     * @param ImageRenderSignalData $render First request for the copy
     * @param string $signature Rendering settings fingerprint
     * @param list<HttpRequestDTO> $requests Waiting requests
     * @return self Missing original, for the library to answer 404
     * @throws InvalidFormatException When a result field is malformed
     */
    public static function missing(ImageRenderSignalData $render, string $signature, array $requests): self
    {
        return new self(ImageRenderOutcome::MISSING, $render->fileId, $render->variant, $signature, $requests);
    }

    /** @return array<string, mixed> Wire payload with all waiting HTTP requests */
    public function toArray(): array
    {
        return [
            self::outcome => $this->outcome->value,
            self::fileId => $this->fileId,
            self::variant => $this->variant,
            self::signature => $this->signature,
            self::requests => array_map(static fn(HttpRequestDTO $request): array => $request->toArray(), $this->requests),
            self::tmpIndex => $this->tmpIndex,
            self::mimeType => $this->mimeType,
            self::size => $this->size,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored result
     * @throws InvalidFormatException When a required field is absent, mistyped or inconsistent with the outcome
     */
    public static function fromArray(array $data): static
    {
        $outcome = ImageRenderOutcome::tryFrom(self::requireString($data, self::outcome))
            ?? throw new InvalidFormatException('Unknown image render outcome');
        $values = self::requireArray($data, self::requests);
        if (!array_is_list($values)) {
            throw new InvalidFormatException('Image result requests must be a list');
        }
        $requests = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new InvalidFormatException('Image result request must be an object');
            }
            $requests[] = HttpRequestDTO::fromArray($value);
        }

        return new static(
            $outcome,
            self::requireInt($data, self::fileId),
            self::requireString($data, self::variant),
            self::requireString($data, self::signature),
            $requests,
            self::optionalString($data, self::tmpIndex),
            self::optionalString($data, self::mimeType),
            self::optionalInt($data, self::size),
        );
    }
}
