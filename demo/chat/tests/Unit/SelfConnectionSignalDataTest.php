<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the self-connection browser row DTO.
 *
 * The row carries no upload state since the chat's files travel through the framework uploads
 * client (HIL-144); what it keeps is the session-local moderation and rate limit.
 */
final class SelfConnectionSignalDataTest extends TestCase
{
    /**
     * Self-connection row payload preserves session-local browser fields.
     */
    public function testSelfConnectionUpdateRoundtrip(): void
    {
        $selfConnection = [
            'userId' => 7,
            'connectedAt' => 1710000000,
            'messageRateLimitSecondsRemaining' => 6,
            'outboundModerationState' => [
                'phase' => ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
                'text' => 'hello',
                'reason' => null,
                'updatedAt' => 1710000001,
            ],
        ];

        $restored = SelfConnectionSignalData::fromArray(
            new SelfConnectionSignalData($selfConnection)->toArray(),
        );

        $this->assertSame($selfConnection, $restored->selfConnection);
        $this->assertSame(['selfConnection' => $selfConnection], $restored->toArray());
    }
}
