<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ActionFailSignalData}.
 *
 * Pins down the envelope contract consumed by
 * {@see DaemonManager::mergeEnvelopeMetadata()}:
 * envelope outcome is `fail`, and the inner payload contains a
 * human-readable `reason`.
 */
final class ActionFailSignalDataTest extends TestCase
{
    public function testImplementsRequiredInterfaces(): void
    {
        $dto = new ActionFailSignalData('User name cannot be empty');

        $this->assertInstanceOf(SignalDataInterface::class, $dto);
        $this->assertInstanceOf(WebSocketEnvelopeAware::class, $dto);
    }

    public function testEnvelopeOutcomeIsFail(): void
    {
        $dto = new ActionFailSignalData('User name cannot be empty');

        $this->assertSame('fail', $dto->getEnvelopeOutcome());
    }

    public function testEnvelopeTimeDefaultsToNull(): void
    {
        $dto = new ActionFailSignalData('User name cannot be empty');

        $this->assertNull($dto->getEnvelopeTime());
    }

    public function testPayloadContainsReason(): void
    {
        $dto = new ActionFailSignalData('This name is already taken');

        $this->assertSame(
            ['reason' => 'This name is already taken'],
            $dto->toArray(),
        );
    }

    public function testReasonIsPublicReadonlyField(): void
    {
        $dto = new ActionFailSignalData('User name cannot be empty');

        $this->assertSame('User name cannot be empty', $dto->reason);
    }

    public function testReasonIsNeverEmptyInContract(): void
    {
        // The contract is: backend always provides a human-readable reason.
        // We cannot enforce non-empty string at the type level in PHP, but
        // we can at least document and assert the expected shape here so
        // accidental empty-reason regressions surface in code review.
        $dto = new ActionFailSignalData('User name cannot be empty');

        $arr = $dto->toArray();
        $this->assertArrayHasKey('reason', $arr);
        $this->assertIsString($arr['reason']);
        $this->assertNotSame('', $arr['reason']);
    }

    /**
     * Regression: worker → daemon IPC roundtrip must preserve the concrete
     * class so that the envelope `outcome='fail'` marker survives to the
     * outgoing WebSocket frame. See {@see ActionSuccessSignalDataTest} for
     * the full rationale.
     */
    public function testRoundtripPreservesConcreteTypeAndEnvelopeMarker(): void
    {
        $original = new ActionFailSignalData('This name is already taken');

        $restored = ActionFailSignalData::fromArray($original->toArray());

        $this->assertInstanceOf(ActionFailSignalData::class, $restored);
        $this->assertInstanceOf(WebSocketEnvelopeAware::class, $restored);
        $this->assertSame('fail', $restored->getEnvelopeOutcome());
        $this->assertSame('This name is already taken', $restored->reason);
        $this->assertSame(
            ['reason' => 'This name is already taken'],
            $restored->toArray(),
        );
    }

    /**
     * A frame that lost the reason is refused, so the client is never acked
     * with a failure that explains nothing.
     */
    public function testAPayloadWithoutAReasonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('reason');

        ActionFailSignalData::fromArray([]);
    }
}
