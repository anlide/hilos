<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ActionFailSignalData}.
 *
 * Pins down the envelope contract consumed by
 * {@see DaemonManager::mergeEnvelopeMetadata()}:
 * envelope outcome is `fail`, and the inner payload always contains
 * both `reason` (enum-like code) and `message` (human-readable, i18n-ready).
 */
final class ActionFailSignalDataTest extends TestCase
{
    public function testImplementsRequiredInterfaces(): void
    {
        $dto = new ActionFailSignalData('empty', 'User name cannot be empty');

        $this->assertInstanceOf(SignalDataInterface::class, $dto);
        $this->assertInstanceOf(WebSocketEnvelopeAware::class, $dto);
    }

    public function testEnvelopeOutcomeIsFail(): void
    {
        $dto = new ActionFailSignalData('empty', 'User name cannot be empty');

        $this->assertSame('fail', $dto->getEnvelopeOutcome());
    }

    public function testEnvelopeTimeDefaultsToNull(): void
    {
        $dto = new ActionFailSignalData('empty', 'User name cannot be empty');

        $this->assertNull($dto->getEnvelopeTime());
    }

    public function testPayloadContainsReasonAndMessage(): void
    {
        $dto = new ActionFailSignalData('name_taken', 'This name is already taken');

        $this->assertSame(
            ['reason' => 'name_taken', 'message' => 'This name is already taken'],
            $dto->toArray(),
        );
    }

    public function testReasonAndMessageArePublicReadonlyFields(): void
    {
        $dto = new ActionFailSignalData('empty', 'User name cannot be empty');

        $this->assertSame('empty', $dto->reason);
        $this->assertSame('User name cannot be empty', $dto->message);
    }

    public function testMessageIsNeverEmptyInContract(): void
    {
        // The contract is: backend always provides a human-readable message.
        // We cannot enforce non-empty string at the type level in PHP, but
        // we can at least document and assert the expected shape here so
        // accidental empty-message regressions surface in code review.
        $dto = new ActionFailSignalData('empty', 'User name cannot be empty');

        $arr = $dto->toArray();
        $this->assertArrayHasKey('message', $arr);
        $this->assertIsString($arr['message']);
        $this->assertNotSame('', $arr['message']);
    }

    /**
     * Regression: worker → daemon IPC roundtrip must preserve the concrete
     * class so that the envelope `outcome='fail'` marker survives to the
     * outgoing WebSocket frame. See {@see ActionSuccessSignalDataTest} for
     * the full rationale.
     */
    public function testRoundtripPreservesConcreteTypeAndEnvelopeMarker(): void
    {
        $original = new ActionFailSignalData('name_taken', 'This name is already taken');

        $restored = ActionFailSignalData::fromArray($original->toArray());

        $this->assertInstanceOf(ActionFailSignalData::class, $restored);
        $this->assertInstanceOf(WebSocketEnvelopeAware::class, $restored);
        $this->assertSame('fail', $restored->getEnvelopeOutcome());
        $this->assertSame('name_taken', $restored->reason);
        $this->assertSame('This name is already taken', $restored->message);
        $this->assertSame(
            ['reason' => 'name_taken', 'message' => 'This name is already taken'],
            $restored->toArray(),
        );
    }
}
