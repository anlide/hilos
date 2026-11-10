<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Connections;

use Hilos\Cluster\Connections\ClusterAcceptKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClusterAcceptKeyTest extends TestCase
{
    public function testComposePrefixesTheRandomSuffixWithTheNodeId(): void
    {
        self::assertSame('node-a.AbCdEfGhIjKlMnOpQrSt_-', ClusterAcceptKey::compose('node-a', 'AbCdEfGhIjKlMnOpQrSt_-'));
    }

    public function testNodeOfReadsAMintedKey(): void
    {
        self::assertSame('node-a', ClusterAcceptKey::nodeOf('node-a.AbCdEfGhIjKlMnOpQrSt_-'));
    }

    public function testNodeOfUsesTheLastSeparatorForANodeIdWithDots(): void
    {
        self::assertSame('node.a.example', ClusterAcceptKey::nodeOf('node.a.example.0123456789ABCDEFGHIJ_-'));
    }

    /**
     * @param string $acceptKey Key outside the minted cluster form
     */
    #[DataProvider('otherKeyForms')]
    public function testNodeOfReturnsNullForOtherKeyForms(string $acceptKey): void
    {
        self::assertNull(ClusterAcceptKey::nodeOf($acceptKey));
    }

    /** @return iterable<string, array{string}> Unaddressed and malformed key forms */
    public static function otherKeyForms(): iterable
    {
        yield 'test door' => ['ak-cluster-e2e'];
        yield 'numeric key' => ['12345'];
        yield 'empty node' => ['.AbCdEfGhIjKlMnOpQrSt_-'];
        yield 'short suffix' => ['node-a.' . str_repeat('A', 21)];
        yield 'long suffix' => ['node-a.' . str_repeat('A', 23)];
        yield 'invalid suffix character' => ['node-a.' . str_repeat('A', 21) . '+'];
    }
}
