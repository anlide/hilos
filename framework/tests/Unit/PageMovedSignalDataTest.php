<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PageMovedSignalData;
use Hilos\Core\Page\Exception\PageMovedException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The page_moved answer on the wire, and the exception a page raises for it.
 */
final class PageMovedSignalDataTest extends TestCase
{
    public function testTheFrameNameIsPageMoved(): void
    {
        $this->assertSame('page_moved', SignalConstants::PAGE_MOVED);
    }

    public function testTheExceptionCarriesTheNewAddressAsAMovedStatus(): void
    {
        $moved = new PageMovedException('product', ['slug' => 'summer-meadow']);

        $this->assertInstanceOf(PageSubscriptionException::class, $moved);
        $this->assertSame(301, $moved->httpCode);
        $this->assertSame('moved', $moved->errorCode);
        $this->assertSame('Page moved', $moved->getMessage());
        $this->assertSame('product', $moved->targetPage);
        $this->assertSame(['slug' => 'summer-meadow'], $moved->targetParams);
    }

    public function testAllFourKeysSurviveTheWire(): void
    {
        $data = new PageMovedSignalData(
            page: 'product',
            params: ['slug' => 'morning-meadow', 'tab' => 'reviews'],
            targetPage: 'product',
            targetParams: ['slug' => 'summer-meadow', 'tab' => 'reviews'],
        );

        $fromArray = PageMovedSignalData::fromArray($data->toArray());
        $fromJson = PageMovedSignalData::fromJson($data->toJson());

        foreach ([$fromArray, $fromJson] as $restored) {
            $this->assertSame('product', $restored->page);
            $this->assertSame(['slug' => 'morning-meadow', 'tab' => 'reviews'], $restored->params);
            $this->assertSame('product', $restored->targetPage);
            $this->assertSame(['slug' => 'summer-meadow', 'tab' => 'reviews'], $restored->targetParams);
        }
        $this->assertSame(
            '{"page":"product","params":{"slug":"morning-meadow","tab":"reviews"},'
            . '"targetPage":"product","targetParams":{"slug":"summer-meadow","tab":"reviews"}}',
            $data->toJson(),
        );
    }

    public function testEmptyMapsStayMapsOnTheWire(): void
    {
        $data = new PageMovedSignalData(page: 'catalog', params: [], targetPage: 'shop', targetParams: []);

        $this->assertSame('{"page":"catalog","params":{},"targetPage":"shop","targetParams":{}}', $data->toJson());
        $restored = PageMovedSignalData::fromJson($data->toJson());
        $this->assertSame([], $restored->params);
        $this->assertSame([], $restored->targetParams);
    }

    /**
     * @param string $field Key the payload is broken at
     * @param bool $present Whether the key stays in the payload
     * @param mixed $value Value the key holds when present
     */
    #[DataProvider('malformedPayloads')]
    public function testRejectsAMalformedPayload(string $field, bool $present, mixed $value): void
    {
        $payload = [
            'page' => 'product',
            'params' => ['slug' => 'morning-meadow'],
            'targetPage' => 'product',
            'targetParams' => ['slug' => 'summer-meadow'],
        ];
        if ($present) {
            $payload[$field] = $value;
        } else {
            unset($payload[$field]);
        }

        $this->expectException(InvalidFormatException::class);
        PageMovedSignalData::fromArray($payload);
    }

    /**
     * @return array<string, array{string, bool, mixed}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'page missing' => ['page', false, null],
            'params missing' => ['params', false, null],
            'target page missing' => ['targetPage', false, null],
            'target params missing' => ['targetParams', false, null],
            'page empty' => ['page', true, ''],
            'target page empty' => ['targetPage', true, ''],
            'page not a string' => ['page', true, 7],
            'target page not a string' => ['targetPage', true, ['product']],
            'params not an array' => ['params', true, 'slug=morning-meadow'],
            'params a list' => ['params', true, ['morning-meadow']],
            'target params not an array' => ['targetParams', true, 'slug=summer-meadow'],
            'target params a list' => ['targetParams', true, ['summer-meadow']],
            'target param empty' => ['targetParams', true, ['slug' => '']],
            'target param an integer' => ['targetParams', true, ['slug' => 42]],
            'target param null' => ['targetParams', true, ['slug' => null]],
        ];
    }
}
