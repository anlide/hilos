<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Router\SignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for handshake response transport.
 */
final class HandshakeResponseSignalDataTest extends TestCase
{
    public function testImplementsSignalDataInterface(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7);

        $this->assertInstanceOf(SignalDataInterface::class, $data);
    }

    public function testPayloadContainsSelfIdAndPageCatalog(): void
    {
        $pageCatalog = [
            'main' => [
                'label' => 'Main',
                'pathTemplate' => '/',
            ],
        ];

        $data = new HandshakeResponseSignalData(
            selfId: 7,
            pageCatalog: $pageCatalog,
        );

        $this->assertSame(
            [
                'self' => [
                    'id' => 7,
                ],
                'pageCatalog' => $pageCatalog,
            ],
            $data->toArray(),
        );
    }

    public function testRoundtripPreservesPayload(): void
    {
        $data = new HandshakeResponseSignalData(
            selfId: 7,
            pageCatalog: [
                'main' => [
                    'label' => 'Main',
                ],
            ],
        );

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertSame(7, $restored->selfId);
        $this->assertSame($data->pageCatalog, $restored->pageCatalog);
        $this->assertSame($data->toArray(), $restored->toArray());
    }
}
