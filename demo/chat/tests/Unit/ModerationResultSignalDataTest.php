<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Hilos\Core\Router\ActionErrorSignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for moderation result signal transport metadata.
 */
final class ModerationResultSignalDataTest extends TestCase
{
    public function testAcceptKeyIsExposedForWorkerExecutionContext(): void
    {
        $data = new ModerationResultSignalData(
            requestId: 'request-1',
            acceptKey: 'moderation-ak',
            userId: 42,
            message: 'approved',
            allow: true,
            reason: 'ok',
        );

        $this->assertSame('moderation-ak', $data->getAcceptKey());
    }

    public function testRoundtripPreservesAcceptKey(): void
    {
        $data = new ModerationResultSignalData(
            requestId: 'request-1',
            acceptKey: 'moderation-ak',
            userId: 42,
            message: 'approved',
            allow: true,
            reason: 'ok',
        );

        $restored = ModerationResultSignalData::fromArray($data->toArray());

        $this->assertSame('moderation-ak', $restored->getAcceptKey());
    }

    public function testExposesMessageActionErrorContext(): void
    {
        $data = new ModerationResultSignalData(
            requestId: 'request-1',
            acceptKey: 'moderation-ak',
            userId: 42,
            message: 'blocked text',
            allow: false,
            reason: 'policy',
        );

        $this->assertInstanceOf(ActionErrorSignalDataInterface::class, $data);
        $this->assertSame(ChatSignalConstants::MESSAGE, $data->getActionErrorName());
        $this->assertSame(['content' => 'blocked text'], $data->getActionErrorPayload());
    }
}
