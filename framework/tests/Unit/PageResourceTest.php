<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The row a page is about, and the column its address is read from.
 */
final class PageResourceTest extends TestCase
{
    public function testARowWithoutAnAddressNamesNeitherParamNorColumn(): void
    {
        $resource = PageResource::db('products', 12);

        $this->assertSame('products', $resource->collectionKey);
        $this->assertSame('12', $resource->idString);
        $this->assertNull($resource->addressParam);
        $this->assertNull($resource->addressColumn);
    }

    public function testAddressedByReturnsANewReferenceToTheSameRow(): void
    {
        $plain = PageResource::db('products', 12);
        $addressed = $plain->addressedBy('slug', 'slug');

        $this->assertNotSame($plain, $addressed);
        $this->assertNull($plain->addressParam);
        $this->assertNull($plain->addressColumn);
        $this->assertSame('products', $addressed->collectionKey);
        $this->assertSame('12', $addressed->idString);
        $this->assertSame('slug', $addressed->addressParam);
        $this->assertSame('slug', $addressed->addressColumn);
    }

    /**
     * @param string $param Route param handed to addressedBy
     * @param string $column Column handed to addressedBy
     */
    #[DataProvider('emptyAddresses')]
    public function testAnEmptyParamOrColumnIsABrokenDeclaration(string $param, string $column): void
    {
        $this->expectException(PageInternalErrorException::class);
        PageResource::db('products', 12)->addressedBy($param, $column);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function emptyAddresses(): array
    {
        return [
            'empty param' => ['', 'slug'],
            'empty column' => ['slug', ''],
            'both empty' => ['', ''],
        ];
    }
}
