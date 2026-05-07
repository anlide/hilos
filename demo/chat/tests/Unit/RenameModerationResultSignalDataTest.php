<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Hilos\Core\Router\ActionErrorSignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for rename moderation result signal transport metadata.
 */
final class RenameModerationResultSignalDataTest extends TestCase
{
    public function testAcceptKeyIsExposedForWorkerExecutionContext(): void
    {
        $data = new RenameModerationResultSignalData(
            acceptKey: 'rename-ak',
            userId: 42,
            newName: 'Alice',
            allow: true,
            reason: 'ok',
        );

        $this->assertSame('rename-ak', $data->getAcceptKey());
    }

    public function testRoundtripPreservesAcceptKey(): void
    {
        $data = new RenameModerationResultSignalData(
            acceptKey: 'rename-ak',
            userId: 42,
            newName: 'Alice',
            allow: true,
            reason: 'ok',
        );

        $restored = RenameModerationResultSignalData::fromArray($data->toArray());

        $this->assertSame('rename-ak', $restored->getAcceptKey());
        $this->assertSame('Alice', $restored->newName);
    }

    public function testExposesRenameActionErrorContext(): void
    {
        $data = new RenameModerationResultSignalData(
            acceptKey: 'rename-ak',
            userId: 42,
            newName: 'BlockedName',
            allow: false,
            reason: 'policy',
        );

        $this->assertInstanceOf(ActionErrorSignalDataInterface::class, $data);
        $this->assertSame(ChatSignalConstants::RENAME, $data->getActionErrorName());
        $this->assertSame(['newName' => 'BlockedName'], $data->getActionErrorPayload());
    }
}
