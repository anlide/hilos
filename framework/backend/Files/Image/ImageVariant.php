<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Hilos;

/** A project declaration of a named image copy and its cache fingerprint. */
final readonly class ImageVariant
{
    public const string WIDTH = 'width';
    public const string HEIGHT = 'height';
    public const string FIT = 'fit';
    public const string FORMAT = 'format';
    public const string NAME_PATTERN = '/^[a-z0-9_]{1,64}$/D';
    public const int MAX_SIDE = 4096;
    public const int RENDER_REVISION = 1;
    public const int SIGNATURE_LENGTH = 8;
    public const string SIGNATURE_PATTERN = '/^[0-9a-f]{' . self::SIGNATURE_LENGTH . '}$/D';

    /**
     * @param string $name Project's variant name
     * @param int $width Frame width in pixels
     * @param int $height Frame height in pixels
     * @param ImageFit $fit How to fit the frame
     * @param ImageFormat $format Encoded output format
     */
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public ImageFit $fit,
        public ImageFormat $format,
    ) {
    }

    /**
     * @param string $name Key of the project's declaration
     * @param mixed $declaration Catalog entry to validate
     * @return self Validated variant, with WEBP when no format was named
     * @throws InvalidArgumentException When the name or entry is malformed
     */
    public static function fromDeclaration(string $name, mixed $declaration): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Image variant {$name}: name must be 1..64 lowercase letters, digits or underscores");
        }
        if (!is_array($declaration)) {
            throw new InvalidArgumentException("Image variant {$name}: declaration must be an array");
        }
        if (array_diff(array_keys($declaration), [self::WIDTH, self::HEIGHT, self::FIT, self::FORMAT]) !== []) {
            throw new InvalidArgumentException("Image variant {$name}: declaration carries an unknown key");
        }
        foreach ([self::WIDTH, self::HEIGHT] as $side) {
            if (!isset($declaration[$side]) || !is_int($declaration[$side])
                || $declaration[$side] < 1 || $declaration[$side] > self::MAX_SIDE) {
                throw new InvalidArgumentException("Image variant {$name}: {$side} must be an integer in 1.." . self::MAX_SIDE);
            }
        }
        if (!($declaration[self::FIT] ?? null) instanceof ImageFit) {
            throw new InvalidArgumentException("Image variant {$name}: fit must be an ImageFit");
        }
        $format = array_key_exists(self::FORMAT, $declaration) ? $declaration[self::FORMAT] : ImageFormat::WEBP;
        if (!$format instanceof ImageFormat) {
            throw new InvalidArgumentException("Image variant {$name}: format must be an ImageFormat");
        }

        return new self($name, $declaration[self::WIDTH], $declaration[self::HEIGHT], $declaration[self::FIT], $format);
    }

    /**
     * @return array<string, self> Validated project catalog, keyed by variant name
     * @throws InvalidArgumentException When a project entry is malformed
     */
    public static function declared(): array
    {
        $variants = [];
        foreach (Hilos::appClass()::imageVariants() as $name => $declaration) {
            $variants[$name] = self::fromDeclaration((string)$name, $declaration);
        }

        return $variants;
    }

    /**
     * @param string $name Variant name
     * @return ?self Declared variant, or null when no declaration names it
     * @throws InvalidArgumentException When a project entry is malformed
     */
    public static function named(string $name): ?self
    {
        return self::declared()[$name] ?? null;
    }

    /**
     * The revision changes when rendering changes, so immutable browser caches get a new address.
     *
     * @return string Short lowercase hex fingerprint of the rendering settings
     */
    public function signature(): string
    {
        return substr(hash('sha256', "{$this->width}x{$this->height}|{$this->fit->value}|{$this->format->value}|r" . self::RENDER_REVISION),
            0, self::SIGNATURE_LENGTH);
    }
}
