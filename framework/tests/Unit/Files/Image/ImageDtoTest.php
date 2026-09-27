<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Image;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\Image\DTO\ImageRenderedSignalData;
use Hilos\Files\Image\DTO\ImageRenderSignalData;
use Hilos\Files\Image\ImageRenderOutcome;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The waiting HTTP identity, cookie and cluster origin must survive both legs of the render trip. */
final class ImageDtoTest extends TestCase
{
    public function testRequestRoundtripPreservesBothRetryValues(): void
    {
        foreach ([false, true] as $retry) {
            $render = self::render($retry);
            $restored = ImageRenderSignalData::fromJson($render->toJson());
            self::assertSame($render->toArray(), $restored->toArray());
            self::assertSame($retry, $restored->retry);
            self::assertSame('node-b', $restored->request->originNodeId);
            self::assertSame('session-token', $restored->request->sessionToken);
        }
    }

    public function testAllResultFactoriesRoundtripWithEveryRequest(): void
    {
        $render = self::render();
        $requests = [$render->request, new HttpRequestDTO(str_repeat('b', 32), 'GET', '/_hilos/file', ['id' => '17'], null, null)];
        $results = [
            ImageRenderedSignalData::rendered($render, '0123abcd', $requests, 'tmp-copy', 'image/webp', 128),
            ImageRenderedSignalData::ready($render, '0123abcd', $requests),
            ImageRenderedSignalData::failed($render, '0123abcd', $requests),
            ImageRenderedSignalData::missing($render, '0123abcd', $requests),
        ];
        foreach ($results as $result) {
            $restored = ImageRenderedSignalData::fromJson($result->toJson());
            self::assertSame($result->toArray(), $restored->toArray());
            self::assertSame($result->outcome, $restored->outcome);
            self::assertSame('node-b', $restored->requests[0]->originNodeId);
            self::assertNull($restored->requests[1]->originNodeId);
        }
        self::assertSame(ImageRenderOutcome::RENDERED, $results[0]->outcome);
    }

    /** @return iterable<string, array{string, mixed}> Invalid request fields */
    public static function badRequests(): iterable
    {
        yield 'retry absent' => ['retry', null];
        yield 'retry integer' => ['retry', 1];
        yield 'retry string' => ['retry', 'false'];
        yield 'id zero' => ['fileId', 0];
        yield 'id negative' => ['fileId', -1];
        yield 'id string' => ['fileId', '17'];
        yield 'storage empty' => ['storedName', ''];
        yield 'type empty' => ['mimeType', ''];
        yield 'variant path' => ['variant', '../thumb'];
        yield 'variant upper' => ['variant', 'Thumb'];
        yield 'request missing fields' => ['request', []];
    }

    /**
     * @param string $key Field to corrupt
     * @param mixed $value Invalid value; null removes the field
     */
    #[DataProvider('badRequests')]
    public function testRequestRefusesMalformedFields(string $key, mixed $value): void
    {
        $payload = self::render()->toArray();
        if ($value === null) {
            unset($payload[$key]);
        } else {
            $payload[$key] = $value;
        }
        $this->expectException(InvalidFormatException::class);
        ImageRenderSignalData::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Result fields that break the outcome contract */
    public static function badResults(): iterable
    {
        yield 'no temporary file' => [['tmpIndex' => null]];
        yield 'empty temporary file' => [['tmpIndex' => '']];
        yield 'no type' => [['mimeType' => null]];
        yield 'no size' => [['size' => null]];
        yield 'zero size' => [['size' => 0]];
        yield 'negative size' => [['size' => -1]];
        yield 'failed with a file' => [['outcome' => 'failed']];
        yield 'ready with a file' => [['outcome' => 'ready']];
        yield 'missing with a file' => [['outcome' => 'missing']];
        yield 'unknown outcome' => [['outcome' => 'unknown']];
        yield 'no requests' => [['requests' => []]];
        yield 'request not an object' => [['requests' => ['request']]];
        yield 'request not a list' => [['requests' => ['named' => []]]];
        yield 'invalid signature' => [['signature' => 'ABCDEFGH']];
        yield 'short signature' => [['signature' => 'abcd']];
        yield 'bad variant' => [['variant' => 'thumb/path']];
        yield 'id zero' => [['fileId' => 0]];
    }

    /** @param array<string, mixed> $changes Invalid fields replacing the rendered payload */
    #[DataProvider('badResults')]
    public function testResultRefusesInconsistentFields(array $changes): void
    {
        $render = self::render();
        $payload = ImageRenderedSignalData::rendered($render, '0123abcd', [$render->request], 'tmp-copy', 'image/webp', 128)->toArray();
        $this->expectException(InvalidFormatException::class);
        ImageRenderedSignalData::fromArray(array_replace($payload, $changes));
    }

    /**
     * @param bool $retry Whether to demand a render after an inaccurate handed-over hint
     * @return ImageRenderSignalData A request carrying all HTTP metadata
     */
    private static function render(bool $retry = false): ImageRenderSignalData
    {
        return new ImageRenderSignalData(
            new HttpRequestDTO(str_repeat('a', 32), 'GET', '/_hilos/file', ['id' => '17', 'variant' => 'thumb'], 'session-token', 'node-b'),
            17, 'source.png', 'image/png', 'thumb', $retry,
        );
    }
}
