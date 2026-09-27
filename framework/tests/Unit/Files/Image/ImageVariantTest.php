<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Image;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Files\Image\ImageFit;
use Hilos\Files\Image\ImageFormat;
use Hilos\Files\Image\ImageVariant;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Validates the project declaration and its immutable-cache fingerprint. */
final class ImageVariantTest extends TestCase
{
    /** The default output and explicit output have exactly the same address signature. */
    public function testDefaultsAndSignature(): void
    {
        $variant = ImageVariant::fromDeclaration('thumb', self::declaration());
        self::assertSame(ImageFormat::WEBP, $variant->format);
        self::assertSame(substr(hash('sha256', '384x384|contain|image/webp|r1'), 0, 8), $variant->signature());
        self::assertSame($variant->signature(), ImageVariant::fromDeclaration('other_name', self::declaration())->signature());
        self::assertSame($variant->signature(), ImageVariant::fromDeclaration('thumb', [
            ...self::declaration(), ImageVariant::FORMAT => ImageFormat::WEBP,
        ])->signature());
        foreach ([
            [ImageVariant::WIDTH => 385],
            [ImageVariant::HEIGHT => 385],
            [ImageVariant::FIT => ImageFit::COVER],
            [ImageVariant::FORMAT => ImageFormat::JPEG],
            [ImageVariant::FORMAT => ImageFormat::PNG],
        ] as $change) {
            self::assertNotSame($variant->signature(),
                ImageVariant::fromDeclaration('thumb', array_replace(self::declaration(), $change))->signature());
        }
    }

    /** @return iterable<string, array{string, mixed}> Invalid names and declaration fields */
    public static function invalidDeclarations(): iterable
    {
        foreach (['', 'Thumb', 'two-words', 'space here', str_repeat('a', 65), "thumb\n"] as $name) {
            yield 'name ' . json_encode($name) => [$name, self::declaration()];
        }
        yield 'not an array' => ['thumb', '384'];
        yield 'unknown key' => ['thumb', [...self::declaration(), 'quality' => 50]];
        foreach ([ImageVariant::WIDTH, ImageVariant::HEIGHT] as $side) {
            $missing = self::declaration();
            unset($missing[$side]);
            yield 'missing ' . $side => ['thumb', $missing];
            foreach ([null, 0, -1, 4097, '384', 384.0, true] as $bad) {
                yield $side . ' ' . json_encode($bad) => ['thumb', array_replace(self::declaration(), [$side => $bad])];
            }
        }
        yield 'missing fit' => ['thumb', [ImageVariant::WIDTH => 10, ImageVariant::HEIGHT => 10]];
        yield 'string fit' => ['thumb', array_replace(self::declaration(), [ImageVariant::FIT => 'contain'])];
        yield 'string format' => ['thumb', [...self::declaration(), ImageVariant::FORMAT => 'webp']];
        yield 'null format' => ['thumb', [...self::declaration(), ImageVariant::FORMAT => null]];
    }

    /**
     * @param string $name Variant name
     * @param mixed $declaration Malformed project entry
     */
    #[DataProvider('invalidDeclarations')]
    public function testMalformedDeclarationsAreRefused(string $name, mixed $declaration): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image variant ' . $name . ':');
        ImageVariant::fromDeclaration($name, $declaration);
    }

    /** Looks up the active application's declarations without creating a database. */
    public function testTheActiveProjectOwnsTheCatalog(): void
    {
        $previous = Hilos::appClass();
        $property = new ReflectionProperty(Hilos::class, 'appClass');
        $property->setValue(null, ImageVariantTestHilos::class);
        try {
            self::assertSame(['thumb'], array_keys(ImageVariant::declared()));
            self::assertSame(384, ImageVariant::named('thumb')?->width);
            self::assertNull(ImageVariant::named('missing'));
        } finally {
            $property->setValue(null, $previous);
        }
    }

    /** @return array<string, mixed> Small valid declaration */
    private static function declaration(): array
    {
        return [ImageVariant::WIDTH => 384, ImageVariant::HEIGHT => 384, ImageVariant::FIT => ImageFit::CONTAIN];
    }
}

/** Declares only the image catalog; the test never initializes the facade. */
final class ImageVariantTestHilos extends Hilos
{
    public const array IMAGE_VARIANTS = [
        'thumb' => [ImageVariant::WIDTH => 384, ImageVariant::HEIGHT => 384, ImageVariant::FIT => ImageFit::CONTAIN],
    ];

    /** @return HilosDbContext Inert framework context */
    protected static function createDb(): HilosDbContext
    {
        return new class extends HilosDbContext {};
    }
}
